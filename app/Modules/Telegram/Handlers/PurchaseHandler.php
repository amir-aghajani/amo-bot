<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\PlanCategoryService;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Support\Money;
use App\Support\Traffic;

/**
 * The customer's way from a plan to a subscription:
 *   plan:{plan}                       → the plan's details and its servers (by name)
 *   plan:{plan}:srv:{server}          → the checkout (CheckoutScreen): the plan on that server and the ways to pay it
 *   plan:{plan}:srv:{server}:{method} → pay with that method: the order is made now, or the open one of the same plan
 *                                       and server found (OrderService::openPurchase())
 *   checkout:{order}                  → a payment reminder's «پرداخت دوباره»: the checkout of what that order buys — a
 *                                       plan, a service's renewal, a top-up, an agent's traffic —, while the customer may
 *                                       still pay it
 */
final class PurchaseHandler implements Handler
{
    public const PLAN = 'plan:';
    public const CHECKOUT = 'checkout:';

    private const SERVER = 'srv';

    public function __construct(
        private readonly ServerSelector $selector,
        private readonly PlanCategoryService $categories,
        private readonly OrderService $orders,
        private readonly PaymentMethods $methods,
        private readonly CheckoutScreen $screen,
        private readonly TopUpHandler $topUps,
        private readonly AgencyHandler $agency,
        private readonly RenewalHandler $renewals,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** The plan's details and servers. */
    public static function planCallback(int $planId): string
    {
        return CallbackData::build(self::PLAN, $planId);
    }

    /** The checkout of the plan on that server. */
    public static function serverCallback(int $planId, int $serverId): string
    {
        return CallbackData::build(self::PLAN, $planId, self::SERVER, $serverId);
    }

    /** The checkout of what an order buys again — the payment reminder's «پرداخت دوباره». */
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

    private function plan(Context $ctx, int $planId): void
    {
        $plan = Plan::active()->find($planId);
        if ($plan === null) {
            $ctx->edit($this->texts->get(BotText::PlanGone), ['reply_markup' => $this->buttons->backOnly($this->listOf(null))]);

            return;
        }

        $description = trim((string) $plan->description);
        $text = $this->texts->render(BotText::PlanDetails, [
            'plan' => $plan->name,
            'description' => $description === '' ? '' : "\n" . $description,
            'traffic' => Messages::traffic($plan->trafficBytes()),
            'duration' => Messages::duration($plan->duration_days),
            'devices' => Messages::devices($plan->ip_limit),
            'price' => Money::format($plan->price),
        ]);
        $choices = $this->selector->covers($plan) ? $this->selector->choices($plan) : [];
        if ($choices === []) {
            $ctx->edit(BotTexts::paragraphs($text, $this->texts->get(BotText::PlanNoServer)), ['reply_markup' => $this->buttons->backOnly($this->listOf($plan))]);

            return;
        }

        $servers = array_map(fn(array $choice): array => InlineKeyboard::callback(
            $this->texts->render(BotText::ServerButton, ['server' => $choice['server']->name]),
            self::serverCallback($plan->id, $choice['server']->id),
        ), $choices);
        $keyboard = InlineKeyboard::make()->grid($servers, 1)->row($this->buttons->back($this->listOf($plan)));

        $ctx->edit(BotTexts::paragraphs($text, $this->texts->part(BotText::PlanPickServer)), ['reply_markup' => $keyboard->build()]);
    }

    /** The plan on the server the customer picked, and the ways to pay it — nothing ordered yet. */
    private function checkout(Context $ctx, int $planId, int $serverId): void
    {
        $choice = $this->choice($ctx, $planId, $serverId);
        if ($choice === null) {
            return;
        }
        [$plan, $server] = $choice;

        $this->screen->show($ctx, $this->texts->render(BotText::Checkout, [
            'plan' => $plan->name,
            'server' => $server->name,
            'amount' => Money::format($plan->price),
        ]), OrderType::Purchase, static fn(PaymentMethod $method): string => CallbackData::build(self::PLAN, $plan->id, self::SERVER, $server->id, $method->id), self::planCallback($plan->id));
    }

    /** A way to pay picked: the purchase ordered — or the open one found — and paid. */
    private function buy(Context $ctx, int $planId, int $serverId, int $methodId): void
    {
        $choice = $this->choice($ctx, $planId, $serverId);
        if ($choice === null) {
            return;
        }
        [$plan, $server] = $choice;
        $method = $this->methods->payable($methodId, OrderType::Purchase);
        if ($method === null) {
            // Switched off (or deleted) since the checkout was shown: the ways that are left.
            $this->checkout($ctx, $planId, $serverId);

            return;
        }

        $this->screen->buy($ctx, $method, Money::normalize($plan->price), fn(): Order => $this->orders->openPurchase($ctx->user, $plan, $server), self::serverCallback($plan->id, $server->id));
    }

    /**
     * The reminder's «پرداخت دوباره»: the checkout of what the order buys while the customer may still pay it (paying
     * finds the order again) — not once it was paid, cancelled or its receipt is with support.
     */
    private function reopen(Context $ctx, int $orderId): void
    {
        $order = Order::payable()->where('user_id', $ctx->user->id)->find($orderId);

        match (true) {
            $order === null => $ctx->edit($this->texts->get(BotText::OrderNotPending), ['reply_markup' => $this->buttons->backOnly($this->listOf(null))]),
            $order->type === OrderType::WalletTopUp => $this->topUps->checkout($ctx, (int) $order->amount),
            $order->type === OrderType::Traffic => $this->agency->checkout($ctx, intdiv((int) $order->traffic_bytes, Traffic::GIGABYTE)),
            $order->type === OrderType::Renewal => $this->renewal($ctx, $order),
            default => $this->checkout($ctx, (int) $order->plan_id, (int) $order->server_id),
        };
    }

    /** A renewal's checkout of its service — unless the service went meanwhile. */
    private function renewal(Context $ctx, Order $order): void
    {
        $subscription = $order->subscription;
        if ($subscription === null || $subscription->status === SubscriptionStatus::Deleted) {
            $ctx->edit($this->texts->get(BotText::ServiceNotFound), ['reply_markup' => $this->buttons->backOnly(MenuHandler::subscriptionsCallback(1))]);

            return;
        }

        $this->renewals->checkout($ctx, $subscription);
    }

    /**
     * The plan and the server the customer picked, while the plan is sold there; null once the customer has been told
     * otherwise.
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
            $ctx->edit($this->texts->get(BotText::PlanNoServer), ['reply_markup' => $this->buttons->backOnly($this->listOf($plan))]);

            return null;
        }

        return [$plan, $server];
    }

    /** The list this plan came from: its category (or "other") when the shop is grouped, else the plain plan list. */
    private function listOf(?Plan $plan): string
    {
        if ($plan === null || !$this->categories->isGrouped()) {
            return MainMenu::PLANS;
        }

        return MenuHandler::categoryCallback($plan->category !== null && $plan->category->is_active ? $plan->category->id : null);
    }
}
