<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Catalog\DTO\CustomPrice;
use App\Modules\Catalog\DTO\Package;
use App\Modules\Catalog\Enums\CustomQuantity;
use App\Modules\Catalog\Services\CustomServices;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Modules\Telegram\Update\CallbackData;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;

/**
 * «🛠 ساخت سرویس دلخواه» (the main bot only, while CustomServices offers it): a service of the GB and days the customer
 * chooses, on one of the builder's servers, bought through the shared checkout as a purchase without a plan:
 *   custom:                              → the GB: a button per amount offered, or any typed (state `custom.gb`)
 *   custom:{gb}                          → the days: a button per amount offered, or any typed (state `custom.days`)
 *   custom:{gb}:{days}                   → a button per server — straight on to the checkout when there is one
 *   custom:{gb}:{days}:{server}          → the checkout: the GB's and the days' prices, the discounts, the service's name
 *                                          (CheckoutScreen), the ways to pay
 *   custom:{gb}:{days}:{server}:{method} → pay: ordered with the name chosen (OrderService::openCustomPurchase()), or the
 *                                          open one found
 * The same two steps renew a service without a plan with a package built, while CustomerRenewal::buildable() — «🛠
 * تمدید با سرویس دلخواه» beside the plans of its renewal (RenewalHandler). No server is picked (a renewal makes no new
 * client), and the checkout is the renewal's own (RenewalHandler::checkout(): its window, methods and discount code):
 *   custom:r:{service}:{from}                            → the GB (`from` is where the renewal was opened, as RenewalHandler's)
 *   custom:r:{service}:{from}:{gb}                       → the days
 *   custom:r:{service}:{from}:{gb}:{days}[:{method}]     → the renewal's checkout, by the method (RenewalHandler::methodArg())
 *   custom:r:{service}:{from}:{gb}:{days}:{method}:{pay} → pay: a renewal of no plan (OrderService::openCustomRenewal())
 * An amount typed outside the bounds is refused, saying them, and another awaited; one tapped that left them since shows
 * its step again. Once nothing is offered, the customer is told so, with the way back to the plans (or to the renewal).
 */
final class CustomServiceHandler implements Handler, RedrawableCheckout
{
    public const PREFIX = 'custom:';
    public const STATE = 'custom';

    private const GB_STATE = 'custom.gb';
    private const DAYS_STATE = 'custom.days';

    /** A renewal's steps, after the prefix. */
    private const RENEW = 'r';

    /** The scratch's GB while the days are awaited, and — renewing — the service and where its renewal was opened from. */
    private const GB = 'gb';
    private const SERVICE = 'service';
    private const FROM = 'from';

    public function __construct(
        private readonly CustomServices $custom,
        private readonly OrderService $orders,
        private readonly Pricing $pricing,
        private readonly CheckoutScreen $screen,
        private readonly RenewalHandler $renewals,
        private readonly TypedAmounts $amounts,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** The days for `$gb` GB. */
    public static function daysCallback(int $gb): string
    {
        return CallbackData::build(self::PREFIX, $gb);
    }

    /** The server for `$gb` GB and `$days` days. */
    public static function serverCallback(int $gb, int $days): string
    {
        return CallbackData::build(self::PREFIX, $gb, $days);
    }

    /** The checkout of `$gb` GB for `$days` days on the server. */
    public static function checkoutCallback(int $gb, int $days, int $serverId): string
    {
        return CallbackData::build(self::PREFIX, $gb, $days, $serverId);
    }

    /** The GB of a package to renew the service with, its renewal opened from `$from` (RenewalHandler's). */
    public static function renewalCallback(int $subscriptionId, int $from): string
    {
        return CallbackData::build(self::PREFIX, self::RENEW, $subscriptionId, $from);
    }

    /** Its days, for `$gb` GB. */
    public static function renewalDaysCallback(int $subscriptionId, int $from, int $gb): string
    {
        return CallbackData::build(self::PREFIX, self::RENEW, $subscriptionId, $from, $gb);
    }

    /** The renewal's checkout of `$gb` GB for `$days` days, by `$method` (null: the first the shop offers). */
    public static function renewalCheckoutCallback(int $subscriptionId, int $from, int $gb, int $days, ?RenewalMethod $method = null): string
    {
        $place = RenewalHandler::methodArg($method);

        return CallbackData::build(self::PREFIX, self::RENEW, $subscriptionId, $from, $gb, $days, ...$place > 0 ? [$place] : []);
    }

    /** That checkout's button paying with the way to pay `$wayId`, by `$method`. */
    public static function renewalPayCallback(int $subscriptionId, int $from, int $gb, int $days, RenewalMethod $method, int $wayId): string
    {
        return CallbackData::build(self::PREFIX, self::RENEW, $subscriptionId, $from, $gb, $days, RenewalHandler::methodArg($method), $wayId);
    }

    /**
     * «🛠 ساخت سرویس دلخواه» under the plans (or the categories), while the builder is offered; none otherwise.
     *
     * @return list<array<string, mixed>>
     */
    public function button(): array
    {
        return $this->custom->offered() ? [InlineKeyboard::callback($this->texts->get(BotText::CustomButton), self::PREFIX, 'primary')] : [];
    }

    public function handle(Context $ctx): void
    {
        if (!$ctx->update->isCallback()) {
            $this->typed($ctx);

            return;
        }

        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];
        if (($args[0] ?? null) === self::RENEW) {
            $this->renewal($ctx, array_map(intval(...), array_slice($args, 1)));

            return;
        }
        $args = array_map(intval(...), $args);
        if (count($args) < 2 && !$this->offered($ctx)) {
            return;
        }
        match (count($args)) {
            0 => $this->step($ctx),
            1 => $this->step($ctx, $args[0]),
            2 => $this->server($ctx, $args[0], $args[1]),
            3 => $this->checkout($ctx, $args[0], $args[1], $args[2]),
            4 => $this->buy($ctx, $args[0], $args[1], $args[2], $args[3]),
            default => $ctx->edit($this->texts->get(BotText::Unknown)),
        };
    }

    /**
     * The checkout `custom:{gb}:{days}:{server}`, or a renewal's `custom:r:{service}:{from}:{gb}:{days}[:{method}]`,
     * drawn again, for Checkouts.
     */
    public function drawCheckout(Context $ctx, array $args, ?string $code = null): void
    {
        $renewal = ($args[0] ?? null) === self::RENEW;
        if ($renewal && in_array(count($args), [5, 6], true)) {
            $this->renewal($ctx, array_map(intval(...), array_slice($args, 1)), $code);
        } elseif (!$renewal && count($args) === 3) {
            $this->checkout($ctx, (int) $args[0], (int) $args[1], (int) $args[2], $code);
        } else {
            $ctx->edit($this->texts->get(BotText::Unknown));
        }
    }

    /** The server of the checkout `custom:{gb}:{days}:{server}`, for Checkouts; a renewal's makes no new service. */
    public function serverOf(array $args): ?int
    {
        return count($args) === 3 && $args[0] !== self::RENEW ? (int) $args[2] : null;
    }

    /**
     * The checkout of what an unpaid order built — the payment reminder's «پرداخت دوباره» (PurchaseHandler::reopen()) —
     * as it is priced now: a purchase's with the name it asked for (so paying finds that order), or a renewal's by its
     * method while its service may still be renewed so.
     */
    public function reopen(Context $ctx, Order $order): void
    {
        $gb = intdiv((int) $order->traffic_bytes, Traffic::GIGABYTE);
        if ($order->type !== OrderType::Renewal) {
            $this->screen->keepOrderName($ctx, self::checkoutCallback($gb, (int) $order->days, (int) $order->server_id), $order->client_name);
            $this->checkout($ctx, $gb, (int) $order->days, (int) $order->server_id);

            return;
        }
        $subscription = $this->renewing($ctx, (int) $order->subscription_id, 0);
        if ($subscription !== null) {
            $this->renewalCheckout($ctx, $subscription, 0, $gb, (int) $order->days, $order->renewal_method);
        }
    }

    /**
     * A step of the two: the GB (`$gb` null), or the days for `$gb` GB while it may be built. The quantity's tiers, a
     * button per amount offered (two a row) and «بازگشت» to the step before — before the GB, the plans or the renewal of
     * `$renewing`; any amount within the bounds may be typed instead (state `custom.gb` / `custom.days`).
     */
    private function step(Context $ctx, ?int $gb = null, ?Subscription $renewing = null, int $from = 0): void
    {
        if ($gb !== null && !$this->sells($ctx, $gb, null, $renewing, $from)) {
            return;
        }
        $quantity = $gb === null ? CustomQuantity::Traffic : CustomQuantity::Days;
        [$least, $most] = $this->custom->bounds($quantity);
        $values = ['least' => Persian::number($least), 'most' => Persian::number($most), 'tiers' => $this->tiers($quantity)];
        if ($gb === null) {
            $text = $this->stepText(BotText::CustomTraffic, BotText::CustomRenewTraffic, $renewing, $values);
            $option = fn(int $amount): array => InlineKeyboard::callback($this->texts->render(BotText::CustomTrafficButton, ['traffic' => self::words($quantity, $amount)]), self::stepCallback($renewing, $from, $amount));
            $back = self::outOf($renewing, $from);
            $ctx->session->enter(self::GB_STATE, self::scratch($renewing, $from));
        } else {
            $text = $this->stepText(BotText::CustomDays, BotText::CustomRenewDays, $renewing, ['traffic' => self::gbWords($gb)] + $values);
            $option = fn(int $amount): array => InlineKeyboard::callback($this->texts->render(BotText::CustomDaysButton, ['duration' => self::words($quantity, $amount)]), self::stepCallback($renewing, $from, $gb, $amount));
            $back = self::stepCallback($renewing, $from);
            $ctx->session->enter(self::DAYS_STATE, [self::GB => $gb] + self::scratch($renewing, $from));
        }

        $keyboard = InlineKeyboard::make()->grid(array_map($option, $this->custom->options($quantity)), 2)->row($this->buttons->back($back));
        $ctx->edit($text, ['reply_markup' => $keyboard->build()]);
    }

    /**
     * The third step: a button per server the builder sells on now, in its colour and row, «بازگشت» to the days — or, with
     * only one, its checkout at once. They fit one keyboard: the builder has CustomServiceSettings::SERVERS_MOST at most.
     */
    private function server(Context $ctx, int $gb, int $days): void
    {
        $servers = $this->custom->servers();
        if ($servers === []) {
            $this->unavailable($ctx);

            return;
        }
        if (!$this->sells($ctx, $gb, $days)) {
            return;
        }
        if (count($servers) === 1) {
            $this->checkout($ctx, $gb, $days, $servers[0]->id);

            return;
        }

        $buttons = array_map(fn(Server $server): array => $this->buttons->server($server, self::checkoutCallback($gb, $days, $server->id)), $servers);

        $ctx->session->clear();
        $ctx->edit($this->texts->render(BotText::CustomServer, [
            'traffic' => self::gbWords($gb),
            'duration' => self::dayWords($days),
        ]), ['reply_markup' => InlineKeyboard::make()->flow($buttons)->row($this->buttons->back(self::daysCallback($gb)))->build()]);
    }

    /**
     * The checkout: the GB's and the days' prices, the devices, the server and what is paid after the customer's discounts
     * (Pricing::custom()), with its discount lines, the service's name and the ways to name it, and the ways to pay —
     * nothing ordered yet. «بازگشت» leads to the servers, or to the days when there is only one.
     *
     * @throws DiscountCodeRefusedException when `$typed` does not hold
     */
    private function checkout(Context $ctx, int $gb, int $days, int $serverId, ?string $typed = null): void
    {
        $chosen = $this->chosen($ctx, $gb, $days, $serverId);
        if ($chosen === null) {
            return;
        }
        [$price, $server, $several] = $chosen;
        $at = self::checkoutCallback($gb, $days, $server->id);
        [$quote, $dropped] = $this->screen->quote($ctx, $at, $this->price($ctx, $price, $server), $typed);

        $text = $this->texts->render(BotText::CustomCheckout, [
            'traffic' => self::gbWords($gb),
            'traffic_price' => Money::format($price->traffic),
            'duration' => self::dayWords($days),
            'days_price' => Money::format($price->time),
            'devices' => Messages::devices($this->custom->devices()),
            'server' => $server->name,
            'amount' => Money::format($quote->amount()),
        ]);
        $this->screen->show(
            $ctx,
            $this->screen->priced($text, $quote, $dropped),
            OrderType::Purchase,
            $quote->amount(),
            static fn(PaymentMethod $method): string => CallbackData::build(self::PREFIX, $gb, $days, $server->id, $method->id),
            $several ? self::serverCallback($gb, $days) : self::daysCallback($gb),
            free: !Money::isPositive($quote->amount()),
            quote: $quote,
            checkout: $at,
        );
    }

    /**
     * A way to pay picked: the service ordered at what it costs the customer now, with the name kept for it (or the open
     * order of the same found), and paid. A way to pay switched off since shows the checkout again.
     */
    private function buy(Context $ctx, int $gb, int $days, int $serverId, int $methodId): void
    {
        $chosen = $this->chosen($ctx, $gb, $days, $serverId);
        if ($chosen === null) {
            return;
        }
        [$price, $server] = $chosen;
        $at = self::checkoutCallback($gb, $days, $server->id);
        $name = $this->screen->requestedName($ctx, $at);

        $this->screen->pay(
            $ctx,
            $methodId,
            OrderType::Purchase,
            $at,
            $this->price($ctx, $price, $server),
            fn(?OrderKey $key, Quote $quote): Order => $this->orders->openCustomPurchase($ctx->user, $gb, $days, $server, $key, $quote, $name),
            fn() => $this->checkout($ctx, $gb, $days, $serverId),
        );
    }

    /**
     * `custom:r:{service}:{from}[:…]` (`$args` after `custom:r:`): a step of renewing the customer's service with a
     * package built, while it may be (renewing()); `$code` is the one typed at its checkout.
     *
     * @param list<int> $args
     * @throws DiscountCodeRefusedException when `$code` does not hold
     */
    private function renewal(Context $ctx, array $args, ?string $code = null): void
    {
        $from = max(0, $args[1] ?? 0);
        $subscription = $this->renewing($ctx, $args[0] ?? 0, $from);
        if ($subscription === null) {
            return;
        }
        match (count($args)) {
            2 => $this->step($ctx, null, $subscription, $from),
            3 => $this->step($ctx, $args[2], $subscription, $from),
            4, 5 => $this->renewalCheckout($ctx, $subscription, $from, $args[2], $args[3], RenewalHandler::methodNamed($args[4] ?? 0), $code),
            6 => $this->renewalPay($ctx, $subscription, $from, $args[2], $args[3], RenewalHandler::methodNamed($args[4]) ?? RenewalMethod::Extend, $args[5]),
            default => $ctx->edit($this->texts->get(BotText::Unknown)),
        };
    }

    /**
     * The renewal's checkout of `$gb` GB for `$days` days by `$method` (RenewalHandler::checkout()), while they may be
     * built; the step to choose again shown when not.
     *
     * @throws DiscountCodeRefusedException when `$code` does not hold
     */
    private function renewalCheckout(Context $ctx, Subscription $subscription, int $from, int $gb, int $days, ?RenewalMethod $method = null, ?string $code = null): void
    {
        if ($this->sells($ctx, $gb, $days, $subscription, $from)) {
            $this->renewals->checkout($ctx, $subscription, $from, $code, method: $method, built: $this->custom->quote($gb, $days));
        }
    }

    /** A way to pay picked at that checkout: the renewal paid (RenewalHandler::pay()), while they may be built. */
    private function renewalPay(Context $ctx, Subscription $subscription, int $from, int $gb, int $days, RenewalMethod $method, int $wayId): void
    {
        if ($this->sells($ctx, $gb, $days, $subscription, $from)) {
            $this->renewals->pay($ctx, $subscription, $from, $wayId, $method, built: $this->custom->quote($gb, $days));
        }
    }

    /**
     * The customer's service `$id` while it may be renewed with a package built; null once they were told otherwise: it
     * is not there; it has a plan since, or may not be renewed now (its renewal as it is now, RenewalHandler::checkout());
     * or the builder is offered no more (said, with «بازگشت» to its renewal).
     */
    private function renewing(Context $ctx, int $id, int $from): ?Subscription
    {
        $subscription = $this->renewals->service($ctx, $id, $from);
        if ($subscription === null) {
            $ctx->session->clear();

            return null;
        }
        if (!CustomerRenewal::picksPlan($subscription)) {
            $ctx->session->clear();
            $this->renewals->checkout($ctx, $subscription, $from);

            return null;
        }
        if (!$this->custom->offered()) {
            $this->unavailable($ctx, RenewalHandler::checkoutCallback($id, $from));

            return null;
        }

        return $subscription;
    }

    /**
     * States `custom.gb` and `custom.days`: the amount typed («۵۰ گیگ» is fine) — the next step, as a new message. One
     * outside the bounds is refused, saying them, and another awaited.
     */
    private function typed(Context $ctx): void
    {
        $serviceId = (int) $ctx->session->get(self::SERVICE, 0);
        $from = (int) $ctx->session->get(self::FROM, 0);
        $renewing = $serviceId > 0 ? $this->renewing($ctx, $serviceId, $from) : null;
        if ($serviceId > 0 ? $renewing === null : !$this->offered($ctx)) {
            return;
        }
        $gb = $ctx->session->inState(self::DAYS_STATE) ? (int) $ctx->session->get(self::GB, 0) : null;
        $quantity = $gb === null ? CustomQuantity::Traffic : CustomQuantity::Days;
        [$least, $most] = $this->custom->bounds($quantity);

        $amount = $this->amounts->read(
            $ctx,
            $quantity->typedUnit(),
            fn(int $amount): bool => $this->custom->allowed($quantity, $amount),
            $this->texts->render($gb === null ? BotText::CustomTrafficInvalid : BotText::CustomDaysInvalid, ['least' => Persian::number($least), 'most' => Persian::number($most)]),
            $gb === null ? self::stepCallback($renewing, $from) : self::stepCallback($renewing, $from, $gb),
        );
        match (true) {
            $amount === null => null,
            $gb === null => $this->step($ctx, $amount, $renewing, $from),
            $renewing === null => $this->server($ctx, $gb, $amount),
            default => $this->renewalCheckout($ctx, $renewing, $from, $gb, $amount),
        };
    }

    /**
     * What the customer chose, while it may still be built there: its price, the server, and whether there are several to
     * pick from; null once the step to choose again is shown (the GB, the days or the servers), or that nothing is offered.
     *
     * @return array{CustomPrice, Server, bool}|null
     */
    private function chosen(Context $ctx, int $gb, int $days, int $serverId): ?array
    {
        $servers = $this->custom->servers();
        if ($servers === []) {
            $this->unavailable($ctx);

            return null;
        }
        if (!$this->sells($ctx, $gb, $days)) {
            return null;
        }
        foreach ($servers as $server) {
            if ($server->id === $serverId) {
                return [$this->custom->quote($gb, $days), $server, count($servers) > 1];
            }
        }
        // The server picked takes none now: the servers as they are.
        $this->server($ctx, $gb, $days);

        return null;
    }

    /** Whether `$gb` GB (for `$days` days) may be built; the step to choose again shown when not. */
    private function sells(Context $ctx, int $gb, ?int $days = null, ?Subscription $renewing = null, int $from = 0): bool
    {
        if (!$this->custom->allowed(CustomQuantity::Traffic, $gb)) {
            $this->step($ctx, null, $renewing, $from);

            return false;
        }
        if ($days !== null && !$this->custom->allowed(CustomQuantity::Days, $days)) {
            $this->step($ctx, $gb, $renewing, $from);

            return false;
        }

        return true;
    }

    /** @return \Closure(?string): Quote The built service's price for the customer with a given discount code. */
    private function price(Context $ctx, CustomPrice $price, Server $server): \Closure
    {
        return fn(?string $code): Quote => $this->pricing->custom($ctx->user, $price, $server, $code);
    }

    /** Whether the builder is offered now; when not, the customer is told so, with «بازگشت» to the plans, out of the flow. */
    private function offered(Context $ctx): bool
    {
        if ($this->custom->offered()) {
            return true;
        }
        $this->unavailable($ctx);

        return false;
    }

    /** Nothing may be built now: said, out of the flow, with «بازگشت» to `$back` (the plans, or the renewal). */
    private function unavailable(Context $ctx, string $back = MainMenu::PLANS): void
    {
        $ctx->session->clear();
        $ctx->edit($this->texts->get(BotText::CustomUnavailable), ['reply_markup' => $this->buttons->backOnly($back)]);
    }

    /**
     * A step's text: the purchase's `$buying`, or — renewing a service — `$renewal`, which names it too.
     *
     * @param array<string, scalar|Html> $values
     */
    private function stepText(BotText $buying, BotText $renewal, ?Subscription $renewing, array $values): string
    {
        return $renewing === null ? $this->texts->render($buying, $values) : $this->texts->render($renewal, $values + ['client' => $renewing->remote_name]);
    }

    /**
     * The quantity's tiers, a line each («• از ۵۰ گیگابایت: ۲٬۵۰۰ تومان»); a line the admin emptied leaves nothing behind.
     */
    private function tiers(CustomQuantity $quantity): Html
    {
        $lines = array_map(fn(array $tier): Html => $this->texts->part(BotText::CustomTier, [
            'from' => self::words($quantity, $tier['from']),
            'unit_price' => Money::format($tier['price']),
        ]), $this->custom->tiers($quantity)->tiers);

        return Html::lines(...array_filter($lines, static fn(Html $line): bool => $line->html !== ''));
    }

    /**
     * A step's callback: `custom:{args}` of a purchase, or `custom:r:{service}:{from}:{args}` renewing `$renewing` — the
     * GB with no `$args`, the days with the GB, and with the days too the servers or the renewal's checkout.
     */
    private static function stepCallback(?Subscription $renewing, int $from, int ...$args): string
    {
        return $renewing === null ? CallbackData::build(self::PREFIX, ...$args) : CallbackData::build(self::PREFIX, self::RENEW, $renewing->id, $from, ...$args);
    }

    /**
     * What a step keeps for a renewal: the service, and where its renewal was opened from.
     *
     * @return array<string, int>
     */
    private static function scratch(?Subscription $renewing, int $from): array
    {
        return $renewing === null ? [] : [self::SERVICE => $renewing->id, self::FROM => $from];
    }

    /** Where the builder was opened from: the plans, or the renewal of `$renewing` (its plans to pick, RenewalHandler). */
    private static function outOf(?Subscription $renewing, int $from): string
    {
        return $renewing === null ? MainMenu::PLANS : RenewalHandler::checkoutCallback($renewing->id, $from);
    }

    /** An amount of the quantity in words: «۵۰ گیگابایت», «۳۰ روز». */
    private static function words(CustomQuantity $quantity, int $amount): string
    {
        return match ($quantity) {
            CustomQuantity::Traffic => self::gbWords($amount),
            CustomQuantity::Days => self::dayWords($amount),
        };
    }

    /** GB in words: «۵۰ گیگابایت». */
    private static function gbWords(int $gb): string
    {
        return Messages::traffic(Traffic::bytesOfGb($gb));
    }

    /** Days in words: «۳۰ روز». */
    private static function dayWords(int $days): string
    {
        return Messages::duration($days * Package::HOURS_A_DAY);
    }
}
