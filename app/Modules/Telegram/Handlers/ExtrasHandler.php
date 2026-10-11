<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\ValidationException;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Subscriptions\DTO\ExtraOffer;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerExtras;
use App\Modules\Subscriptions\Services\ServiceTerms;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Modules\Telegram\Update\CallbackData;
use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;

/**
 * «حجم و زمان اضافه»: extra traffic (GB) or time (days) a customer buys for one of their services, through the shared
 * checkout. What a service may take and at what price is CustomerExtras':
 *   extra:{service}:{kind}              → the kind's screen: a button per amount offered with its price; while the
 *                                         shop prices any amount, one typed is awaited too (state `extra.{kind}`)
 *   extra:{service}:{kind}:{q}          → the checkout of `q` GB or days, with the service as it would leave it
 *   extra:{service}:{kind}:{q}:{method} → pay at what it costs now: ordered, or the open order of the same found
 * A service that cannot take the kind now gets a popup saying why; an amount no longer sold shows the kind's screen.
 */
final class ExtrasHandler implements Handler, DiscountableCheckout
{
    public const PREFIX = 'extra:';
    public const STATE = 'extra';

    /** The scratch key of the service while an amount typed is awaited. */
    private const SERVICE = 'service';

    public function __construct(
        private readonly CustomerExtras $extras,
        private readonly OrderService $orders,
        private readonly Pricing $pricing,
        private readonly PaymentMethods $methods,
        private readonly CheckoutScreen $screen,
        private readonly CustomerServices $services,
        private readonly ServiceCard $card,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** The kind's screen for the service. */
    public static function screenCallback(int $subscriptionId, ExtraKind $kind): string
    {
        return CallbackData::build(self::PREFIX, $subscriptionId, $kind->value);
    }

    /** The checkout of `$quantity` GB or days of the kind for the service. */
    public static function checkoutCallback(int $subscriptionId, ExtraKind $kind, int $quantity): string
    {
        return CallbackData::build(self::PREFIX, $subscriptionId, $kind->value, $quantity);
    }

    /**
     * The row «حجم اضافه» | «زمان اضافه» for the service, each while the shop sells it for that service now (asked once
     * for both); «حجم اضافه» on the right. Under the service's screen and under its renewal's checkout.
     *
     * @return list<array<string, mixed>>
     */
    public function buttons(Subscription $subscription): array
    {
        $offers = $this->extras->offersFor([$subscription])[$subscription->id];
        $buttons = [];
        if ($offers['time'] !== null) {
            $buttons[] = InlineKeyboard::callback($this->texts->get(BotText::ServiceExtraTime), self::screenCallback($subscription->id, ExtraKind::Time));
        }
        if ($offers['traffic'] !== null) {
            $buttons[] = InlineKeyboard::callback($this->texts->get(BotText::ServiceExtraTraffic), self::screenCallback($subscription->id, ExtraKind::Traffic));
        }

        return $buttons;
    }

    public function handle(Context $ctx): void
    {
        if (!$ctx->update->isCallback()) {
            $this->typed($ctx);

            return;
        }

        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];
        $kind = ExtraKind::tryFrom($args[1] ?? '');
        if ($kind === null || count($args) > 4) {
            $ctx->edit($this->texts->get(BotText::Unknown));

            return;
        }
        $subscription = $this->service($ctx, (int) ($args[0] ?? 0));
        if ($subscription === null) {
            return;
        }

        match (count($args)) {
            2 => $this->show($ctx, $subscription, $kind),
            3 => $this->checkout($ctx, $subscription, $kind, (int) $args[2]),
            default => $this->buy($ctx, $subscription, $kind, (int) $args[2], (int) $args[3]),
        };
    }

    /** The checkout `extra:{service}:{kind}:{q}` drawn again, for DiscountCodeHandler. */
    public function drawCheckout(Context $ctx, array $args, ?string $code = null): void
    {
        $kind = ExtraKind::tryFrom($args[1] ?? '');
        if ($kind === null || count($args) !== 3) {
            $ctx->edit($this->texts->get(BotText::Unknown));

            return;
        }
        $subscription = $this->service($ctx, (int) $args[0]);
        if ($subscription !== null) {
            $this->checkout($ctx, $subscription, $kind, (int) $args[2], $code);
        }
    }

    /**
     * The checkout of `$quantity` of the kind: what it costs now with its discount lines, and the service's traffic left
     * and end as it would leave them; nothing ordered yet. An amount no longer sold shows the kind's screen instead.
     *
     * @throws DiscountCodeRefusedException when `$typed` does not hold
     */
    public function checkout(Context $ctx, Subscription $subscription, ExtraKind $kind, int $quantity, ?string $typed = null): void
    {
        $offer = $this->offer($ctx, $subscription, $kind);
        if ($offer === null) {
            return;
        }
        $base = $offer->priceOf($quantity);
        if ($base === null) {
            $this->menu($ctx, $subscription, $kind, $offer);

            return;
        }
        $at = self::checkoutCallback($subscription->id, $kind, $quantity);
        [$quote, $dropped] = $this->screen->quote($ctx, $at, $this->price($ctx, $subscription, $kind, $base), $typed);
        $price = $quote->amount();

        $terms = match ($kind) {
            ExtraKind::Traffic => ServiceTerms::extra($subscription, 0, Traffic::bytesOfGb($quantity)),
            ExtraKind::Time => ServiceTerms::extra($subscription, $quantity, 0),
        };
        $after = $this->card->values($terms->preview($subscription));
        $text = match ($kind) {
            ExtraKind::Traffic => $this->texts->render(BotText::ExtraTrafficCheckout, [
                'client' => $subscription->remote_name,
                'extra_traffic' => $kind->words($quantity),
                'amount' => Money::format($price),
                'remaining' => $after['remaining'],
                'expires' => $after['expires'],
            ]),
            ExtraKind::Time => $this->texts->render(BotText::ExtraTimeCheckout, [
                'client' => $subscription->remote_name,
                'extra_time' => $kind->words($quantity),
                'amount' => Money::format($price),
                'expires' => $after['expires'],
                'remaining' => $after['remaining'],
            ]),
        };

        $pay = static fn(PaymentMethod $method): string => CallbackData::build(self::PREFIX, $subscription->id, $kind->value, $quantity, $method->id);
        $this->screen->show($ctx, $this->screen->priced($text, $quote, $dropped), $kind->orderType(), $pay, self::screenCallback($subscription->id, $kind), free: !Money::isPositive($price), quote: $quote, checkout: $at);
    }

    /** The kind's screen, while the service may take the kind now. */
    private function show(Context $ctx, Subscription $subscription, ExtraKind $kind): void
    {
        $offer = $this->offer($ctx, $subscription, $kind);
        if ($offer !== null) {
            $this->menu($ctx, $subscription, $kind, $offer);
        }
    }

    /**
     * The kind's screen: the service's traffic left and end, a button per amount offered at the customer's price (less
     * their automatic discount), two a row, and «بازگشت» to the service. While any amount may be typed, the chat awaits
     * one (state `extra.{kind}`); otherwise it awaits nothing.
     */
    private function menu(Context $ctx, Subscription $subscription, ExtraKind $kind, ExtraOffer $offer): void
    {
        $values = $this->card->values($subscription);
        $text = match ($kind) {
            ExtraKind::Traffic => $this->texts->render(BotText::ExtraTrafficMenu, [
                'client' => $values['client'],
                'remaining' => $values['remaining'],
                'expires' => $values['expires'],
                'typed' => $this->typedHint($kind, $offer),
            ]),
            ExtraKind::Time => $this->texts->render(BotText::ExtraTimeMenu, [
                'client' => $values['client'],
                'expires' => $values['expires'],
                'remaining' => $values['remaining'],
                'restart' => $subscription->isExpiredByTime() ? $this->texts->part(BotText::ExtraTimeRestart) : '',
                'typed' => $this->typedHint($kind, $offer),
            ]),
        };

        $automatic = $this->pricing->automatic($ctx->user);
        $options = array_map(fn(array $choice): array => InlineKeyboard::callback($this->texts->render(BotText::ExtraOption, [
            'extra' => $kind->words($choice['quantity']),
            'amount' => Money::format(Money::subtract($choice['price'], $automatic->best($subscription->plan_id, $kind->orderType(), $choice['price'])->amount ?? 0)),
        ]), self::checkoutCallback($subscription->id, $kind, $choice['quantity'])), $offer->choices());
        $keyboard = InlineKeyboard::make()->grid($options, 2)->row($this->buttons->back(SubscriptionHandler::serviceCallback($subscription->id)));

        if ($offer->typed()) {
            $ctx->session->enter(self::STATE . '.' . $kind->value, [self::SERVICE => $subscription->id]);
        } else {
            $ctx->session->clear();
        }
        $ctx->edit($text, ['reply_markup' => $keyboard->build()]);
    }

    /**
     * State `extra.{kind}`: the amount typed («۲۰ گیگ» is fine) — its checkout, as a new message. One not sold is refused
     * and another awaited; once no amount may be typed any more (the prices changed), the kind's screen comes again.
     */
    private function typed(Context $ctx): void
    {
        $kind = ExtraKind::tryFrom(substr((string) $ctx->session->state(), strlen(self::STATE) + 1));
        if ($kind === null) {
            $ctx->session->clear();
            $ctx->reply($this->texts->get(BotText::Unknown));

            return;
        }
        $subscription = $this->service($ctx, (int) $ctx->session->get(self::SERVICE, 0));
        if ($subscription === null) {
            $ctx->session->clear();

            return;
        }
        $offer = $this->offer($ctx, $subscription, $kind);
        if ($offer === null) {
            return;
        }
        if (!$offer->typed()) {
            $this->menu($ctx, $subscription, $kind, $offer);

            return;
        }

        $quantity = Input::amountOf($ctx->update->text());
        if ($quantity === null || $offer->priceOf($quantity) === null) {
            $ctx->reply($this->texts->render(BotText::ExtraInvalid, [
                'least' => Persian::number($offer->min),
                'most' => Persian::number($offer->max),
            ]), ['reply_markup' => $this->buttons->backOnly(self::screenCallback($subscription->id, $kind))]);

            return;
        }

        $this->checkout($ctx, $subscription, $kind, $quantity);
    }

    /**
     * A way to pay picked: the extra ordered (or the open order of the same found) and paid at what it costs now
     * (CustomerExtras::quote()). A service that cannot take it now gets a popup saying why; an amount no longer sold
     * shows the kind's screen; a way to pay switched off since shows the checkout again.
     */
    private function buy(Context $ctx, Subscription $subscription, ExtraKind $kind, int $quantity, int $methodId): void
    {
        try {
            $base = $this->extras->quote($subscription, $kind, $quantity);
        } catch (ValidationException $e) {
            array_key_exists('status', $e->errors()) ? $this->unavailable($ctx, $subscription) : $this->show($ctx, $subscription, $kind);

            return;
        }
        $method = $this->methods->payable($methodId, $kind->orderType());
        if ($method === null) {
            $this->checkout($ctx, $subscription, $kind, $quantity);

            return;
        }

        $at = self::checkoutCallback($subscription->id, $kind, $quantity);
        $quote = $this->screen->priceToPay($ctx, $at, $this->price($ctx, $subscription, $kind, $base));
        if ($quote !== null) {
            $this->screen->buy($ctx, $method, $quote->amount(), fn(?OrderKey $key): Order => $this->orders->openExtra($ctx->user, $subscription, $kind, $quantity, $base, $key, $quote), $at);
        }
    }

    /** @return \Closure(?string): Quote The extra's price for the customer, from the shop's `$base`, with a given code. */
    private function price(Context $ctx, Subscription $subscription, ExtraKind $kind, string $base): \Closure
    {
        return fn(?string $code): Quote => $this->pricing->extra($ctx->user, $subscription, $kind, $base, $code);
    }

    /** What the customer may buy of the kind for the service now; null after saying they may not (unavailable()). */
    private function offer(Context $ctx, Subscription $subscription, ExtraKind $kind): ?ExtraOffer
    {
        $offer = $this->extras->offer($subscription, $kind);
        if ($offer === null) {
            $this->unavailable($ctx, $subscription);
        }

        return $offer;
    }

    /**
     * The service cannot take the kind now: a popup on the button pressed, nothing else changes — or, for an amount
     * typed (no button to answer), a message with the way back to the service, the amount no longer awaited.
     */
    private function unavailable(Context $ctx, Subscription $subscription): void
    {
        if ($ctx->update->isCallback()) {
            $ctx->answer($this->texts->get(BotText::ExtraUnavailable), alert: true);

            return;
        }

        $ctx->session->clear();
        $ctx->reply($this->texts->message(BotText::ExtraUnavailable), ['reply_markup' => $this->buttons->backOnly(SubscriptionHandler::serviceCallback($subscription->id))]);
    }

    /** The hint at an amount typed — its range and the unit price —; '' while no amount may be typed. */
    private function typedHint(ExtraKind $kind, ExtraOffer $offer): Html|string
    {
        if (!$offer->typed() || $offer->price === null) {
            return '';
        }

        return $this->texts->part($kind === ExtraKind::Traffic ? BotText::ExtraTrafficTyped : BotText::ExtraTimeTyped, [
            'least' => Persian::number($offer->min),
            'most' => Persian::number($offer->max),
            'unit_price' => Money::format($offer->price),
        ]);
    }

    /** The customer's service, «بازگشت» to «سرویس‌های من» when it is not there. */
    private function service(Context $ctx, int $id): ?Subscription
    {
        return $this->services->find($ctx, $id, MenuHandler::subscriptionsCallback(1));
    }
}
