<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\PlanCategoryService;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Discounts\Enums\DiscountSource;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\KeyboardLayout;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Support\Money;
use App\Support\Traffic;

/**
 * From a plan to a subscription:
 *   plan:{plan}                       → the plan's details and a button per location (its servers)
 *   plan:{plan}:srv:{server}          → the checkout of the plan on that server
 *   plan:{plan}:srv:{server}:{method} → pay: the order is made now, or the open one of the same is found
 *   checkout:{order}                  → a payment reminder's «پرداخت دوباره» (and «بازگشت» from an online page whose time
 *                                       ran out): the checkout of whatever that order buys (a purchase's with the name it
 *                                       asked for, so paying finds that order)
 * The price shown and charged is the customer's own (Pricing: a first-purchase or agent price, less their automatic
 * discount and the code they typed).
 */
final class PurchaseHandler implements Handler, RedrawableCheckout
{
    public const PLAN = 'plan:';
    public const CHECKOUT = 'checkout:';

    private const SERVER = 'srv';

    public function __construct(
        private readonly ServerSelector $selector,
        private readonly PlanCategoryService $categories,
        private readonly OrderService $orders,
        private readonly Pricing $pricing,
        private readonly CheckoutScreen $screen,
        private readonly CustomerServices $services,
        private readonly TopUpHandler $topUps,
        private readonly AgencyHandler $agency,
        private readonly RenewalHandler $renewals,
        private readonly ExtrasHandler $extras,
        private readonly CustomServiceHandler $custom,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** The plan's details and locations. */
    public static function planCallback(int $planId): string
    {
        return CallbackData::build(self::PLAN, $planId);
    }

    /** The checkout of the plan on that server. */
    public static function serverCallback(int $planId, int $serverId): string
    {
        return CallbackData::build(self::PLAN, $planId, self::SERVER, $serverId);
    }

    /** The checkout of what an order buys — the payment reminder's «پرداخت دوباره». */
    public static function reopenCallback(int $orderId): string
    {
        return CallbackData::build(self::CHECKOUT, $orderId);
    }

    public function handle(Context $ctx): void
    {
        $plan = $ctx->update->callbackArgs(self::PLAN);
        $reopen = $ctx->update->callbackArgs(self::CHECKOUT);

        match (true) {
            $plan !== null && count($plan) === 1 => $this->plan($ctx, (int) $plan[0]),
            $plan !== null && count($plan) === 3 && $plan[1] === self::SERVER => $this->checkout($ctx, (int) $plan[0], (int) $plan[2]),
            $plan !== null && count($plan) === 4 && $plan[1] === self::SERVER => $this->buy($ctx, (int) $plan[0], (int) $plan[2], (int) $plan[3]),
            $reopen !== null && count($reopen) === 1 => $this->reopen($ctx, (int) $reopen[0]),
            default => $ctx->edit($this->texts->get(BotText::Unknown)),
        };
    }

    /** The checkout `plan:{plan}:srv:{server}` drawn again, for Checkouts. */
    public function drawCheckout(Context $ctx, array $args, ?string $code = null): void
    {
        $server = $this->serverOf($args);
        if ($server === null) {
            $ctx->edit($this->texts->get(BotText::Unknown));

            return;
        }
        $this->checkout($ctx, (int) $args[0], $server, $code);
    }

    /** The server of the checkout `plan:{plan}:srv:{server}`, for Checkouts. */
    public function serverOf(array $args): ?int
    {
        return count($args) === 3 && $args[1] === self::SERVER ? (int) $args[2] : null;
    }

    /**
     * The plan's details at the customer's price — a line saying why it differs from the regular price — and a button
     * per location in its server's colour and row; or that no location can sell it now.
     */
    private function plan(Context $ctx, int $planId): void
    {
        $plan = Plan::active()->find($planId);
        if ($plan === null) {
            $ctx->edit($this->texts->get(BotText::PlanGone), ['reply_markup' => $this->buttons->backOnly($this->listOf(null))]);

            return;
        }
        $price = $this->pricing->purchase($ctx->user, $plan);

        $description = trim((string) $plan->description);
        $text = $this->texts->render(BotText::PlanDetails, [
            'plan' => $plan->name,
            'description' => $description === '' ? '' : "\n" . $description,
            'traffic' => Messages::traffic($plan->trafficBytes()),
            'duration' => Messages::duration($plan->duration_hours),
            'devices' => Messages::devices($plan->ip_limit),
            'price' => Money::format($price->amount()),
        ]);
        $why = match ($price->pricing) {
            OrderPricing::FirstPurchase => BotText::PlanFirstPurchase,
            OrderPricing::Agent => BotText::PlanAgentPrice,
            OrderPricing::Regular, OrderPricing::Gift => null,
        };
        if ($why !== null) {
            $text = BotTexts::paragraphs($text, $this->texts->part($why, ['regular' => Money::format($plan->price)]));
        }
        $discount = $price->automatic;
        if ($discount !== null) {
            $text = BotTexts::paragraphs($text, $this->texts->part(match ($discount->source) {
                DiscountSource::Personal => BotText::PlanPersonalDiscount,
                DiscountSource::Tier => BotText::PlanTierDiscount,
                DiscountSource::Sale, DiscountSource::Code => BotText::PlanSale,
            }, ['discount' => $discount->label, 'regular' => Money::format($price->base)]));
        }
        $choices = $this->selector->covers($plan) ? $this->selector->choices($plan) : [];
        if ($choices === []) {
            $ctx->edit(BotTexts::paragraphs($text, $this->texts->get(self::noServer())), ['reply_markup' => $this->buttons->backOnly($this->listOf($plan))]);

            return;
        }

        $servers = array_map(fn(array $choice): array => [InlineKeyboard::callback(
            $this->texts->render(BotText::ServerButton, ['server' => $choice['server']->name]),
            self::serverCallback($plan->id, $choice['server']->id),
            KeyboardLayout::style($choice['server']->buttonStyle()),
        ), $choice['server']->buttonRow()], $choices);
        $keyboard = InlineKeyboard::make()->flow($servers)->row($this->buttons->back($this->listOf($plan)));

        $ctx->edit(BotTexts::paragraphs($text, $this->texts->part(BotText::PlanPickServer)), ['reply_markup' => $keyboard->build()]);
    }

    /**
     * The checkout of the plan on the server picked, at the customer's price with its discount lines, the plan's note
     * and the ways to pay — nothing ordered yet.
     *
     * @throws DiscountCodeRefusedException when `$typed` does not hold
     */
    private function checkout(Context $ctx, int $planId, int $serverId, ?string $typed = null): void
    {
        $choice = $this->choice($ctx, $planId, $serverId);
        if ($choice === null) {
            return;
        }
        [$plan, $server] = $choice;
        $at = self::serverCallback($plan->id, $server->id);
        [$quote, $dropped] = $this->screen->quote($ctx, $at, $this->price($ctx, $plan, $server), $typed);

        $text = $this->texts->render(BotText::Checkout, [
            'plan' => $plan->name,
            'server' => $server->name,
            'amount' => Money::format($quote->amount()),
        ]);
        $this->screen->show(
            $ctx,
            $this->screen->withNote($this->screen->priced($text, $quote, $dropped), $plan),
            OrderType::Purchase,
            $quote->amount(),
            static fn(PaymentMethod $method): string => CallbackData::build(self::PLAN, $plan->id, self::SERVER, $server->id, $method->id),
            self::planCallback($plan->id),
            free: !Money::isPositive($quote->amount()),
            quote: $quote,
            checkout: $at,
        );
    }

    /**
     * A way to pay picked: the purchase ordered at the customer's price, with the name kept for its service (or the open
     * one found), and paid.
     */
    private function buy(Context $ctx, int $planId, int $serverId, int $methodId): void
    {
        $choice = $this->choice($ctx, $planId, $serverId);
        if ($choice === null) {
            return;
        }
        [$plan, $server] = $choice;
        $at = self::serverCallback($plan->id, $server->id);
        $name = $this->screen->requestedName($ctx, $at);

        $this->screen->pay(
            $ctx,
            $methodId,
            OrderType::Purchase,
            $at,
            $this->price($ctx, $plan, $server),
            fn(?OrderKey $key, Quote $quote): Order => $this->orders->openPurchase($ctx->user, $plan, $server, $key, $quote, $name),
            fn() => $this->checkout($ctx, $planId, $serverId),
        );
    }

    /** @return \Closure(?string): Quote The plan's price for the customer with a given discount code. */
    private function price(Context $ctx, Plan $plan, Server $server): \Closure
    {
        return fn(?string $code): Quote => $this->pricing->purchase($ctx->user, $plan, $server, $code);
    }

    /**
     * «پرداخت دوباره»: the checkout of what the order buys, while the customer may still pay it — not once it was paid,
     * cancelled or its receipt is with support.
     */
    private function reopen(Context $ctx, int $orderId): void
    {
        $order = Order::payable()->where('user_id', $ctx->user->id)->find($orderId);
        $extra = $order === null ? null : ExtraKind::of($order->type);

        match (true) {
            $order === null => $ctx->edit($this->texts->get(BotText::OrderNotPending), ['reply_markup' => $this->buttons->backOnly($this->listOf(null))]),
            $order->type === OrderType::WalletTopUp => $this->topUps->checkout($ctx, (int) $order->amount),
            $order->type === OrderType::Traffic => $this->agency->checkout($ctx, intdiv((int) $order->traffic_bytes, Traffic::GIGABYTE)),
            // A package the customer built has no plan: the builder's checkout of it (a purchase's or a renewal's), as it
            // is priced now.
            $order->isBuilt() => $this->custom->reopen($ctx, $order),
            $order->type === OrderType::Renewal => $this->renewal($ctx, $order),
            $extra !== null => $this->extra($ctx, $order, $extra),
            default => $this->purchase($ctx, $order),
        };
    }

    /** A plan's purchase: its checkout with the name the order asked for, so paying it finds that very order. */
    private function purchase(Context $ctx, Order $order): void
    {
        $this->screen->keepOrderName($ctx, self::serverCallback((int) $order->plan_id, (int) $order->server_id), $order->client_name);
        $this->checkout($ctx, (int) $order->plan_id, (int) $order->server_id);
    }

    /** A renewal's checkout by the order's method (a service without a plan on the order's), unless its service went meanwhile. */
    private function renewal(Context $ctx, Order $order): void
    {
        $subscription = $this->services->find($ctx, (int) $order->subscription_id, MenuHandler::subscriptionsCallback(1));
        if ($subscription !== null) {
            $this->renewals->checkout($ctx, $subscription, planId: (int) $order->plan_id, method: $order->renewal_method);
        }
    }

    /** An extra's checkout for as much as the order buys, unless its service went meanwhile. */
    private function extra(Context $ctx, Order $order, ExtraKind $kind): void
    {
        $subscription = $this->services->find($ctx, (int) $order->subscription_id, MenuHandler::subscriptionsCallback(1));
        if ($subscription !== null) {
            $this->extras->checkout($ctx, $subscription, $kind, $kind->quantityOf($order));
        }
    }

    /**
     * The plan and server picked, while the plan still sells there; null once the customer was told otherwise.
     *
     * @return array{Plan, Server}|null
     */
    private function choice(Context $ctx, int $planId, int $serverId): ?array
    {
        $plan = Plan::active()->find($planId);
        if ($plan === null) {
            $ctx->session->clear();
            $ctx->edit($this->texts->get(BotText::PlanGone), ['reply_markup' => $this->buttons->backOnly($this->listOf(null))]);

            return null;
        }
        try {
            $server = $this->selector->covers($plan) ? $this->selector->resolve($plan, $serverId)['server'] : null;
        } catch (NoServerAvailableException) {
            $server = null;
        }
        if ($server === null) {
            $ctx->session->clear();
            $ctx->edit($this->texts->get(self::noServer()), ['reply_markup' => $this->buttons->backOnly($this->listOf($plan))]);

            return null;
        }

        return [$plan, $server];
    }

    /** Why a plan sells nowhere now: no server can — or, an agent's shop whose selling is suspended, nothing is sold. */
    private static function noServer(): BotText
    {
        return !CurrentBot::get()->sells() ? BotText::SellingPaused : BotText::PlanNoServer;
    }

    /** The list the plan came from: its category (or «سایر پلن‌ها») when the shop has categories, else the plan list. */
    private function listOf(?Plan $plan): string
    {
        if ($plan === null || !$this->categories->isGrouped()) {
            return MainMenu::PLANS;
        }

        return MenuHandler::categoryCallback($plan->category !== null && $plan->category->is_active ? $plan->category->id : null);
    }
}
