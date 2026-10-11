<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\RenewalUnderWayException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Subscriptions\DTO\RenewalPreview;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Support\Money;

/**
 * «♻️ تمدید سرویس»: one of the customer's services renewed on its own plan, through the shared checkout:
 *   renew:{service}:{from}          → the checkout, with the service as the renewal would leave it
 *                                     (CustomerRenewal::preview(), the website's too); `from` is where «بازگشت» goes:
 *                                     0 the service's screen, n page n of the menu's renewals list
 *   renew:{service}:{from}:{method} → pay: the renewal ordered now, or the open one found
 * Opened from the service's screen, the menu's list and a payment reminder. A service that may not be renewed now, or
 * whose renewal is already under way, gets a popup saying so and nothing is ordered.
 */
final class RenewalHandler implements Handler, DiscountableCheckout
{
    public const PREFIX = 'renew:';

    public function __construct(
        private readonly CustomerRenewal $renewals,
        private readonly OrderService $orders,
        private readonly Pricing $pricing,
        private readonly PaymentMethods $methods,
        private readonly CheckoutScreen $screen,
        private readonly CustomerServices $services,
        private readonly ExtrasHandler $extras,
        private readonly ServiceCard $card,
        private readonly BotTexts $texts,
    ) {}

    /** The renewal's checkout, opened from the service's screen (`$from` 0) or from that page of the menu's list. */
    public static function checkoutCallback(int $subscriptionId, int $from = 0): string
    {
        return CallbackData::build(self::PREFIX, $subscriptionId, $from);
    }

    public function handle(Context $ctx): void
    {
        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];
        $from = max(0, (int) ($args[1] ?? 0));
        $subscription = $this->service($ctx, (int) ($args[0] ?? 0), $from);
        if ($subscription === null) {
            return;
        }

        isset($args[2]) ? $this->buy($ctx, $subscription, $from, (int) $args[2]) : $this->checkout($ctx, $subscription, $from);
    }

    /** The checkout `renew:{service}:{from}` drawn again, for DiscountCodeHandler. */
    public function drawCheckout(Context $ctx, array $args, ?string $code = null): void
    {
        $from = max(0, (int) ($args[1] ?? 0));
        $subscription = $this->service($ctx, (int) $args[0], $from);
        if ($subscription !== null) {
            $this->checkout($ctx, $subscription, $from, $code);
        }
    }

    /**
     * The renewal's checkout: the plan's term, traffic and the customer's price with its discount lines, the service's
     * end and traffic as the renewal would leave them (and what of the current period's traffic expires), the plan's
     * note, and the ways to pay. From the menu's list it also offers the service's extras: the way to a service whose
     * traffic or time ran out, which «سرویس‌های من» does not list.
     *
     * @throws DiscountCodeRefusedException when `$typed` does not hold
     */
    public function checkout(Context $ctx, Subscription $subscription, int $from = 0, ?string $typed = null): void
    {
        $preview = $this->preview($ctx, $subscription);
        if ($preview === null) {
            return;
        }

        $plan = $preview->plan;
        $at = self::checkoutCallback($subscription->id, $from);
        [$quote, $dropped] = $this->screen->quote($ctx, $at, $this->price($ctx, $subscription, $preview), $typed);
        $values = $this->card->values($preview->after);
        $text = $this->texts->render(BotText::RenewCheckout, [
            'client' => $subscription->remote_name,
            'plan' => $plan->name,
            'duration' => Messages::duration($plan->duration_hours),
            'traffic' => Messages::traffic($plan->trafficBytes()),
            'amount' => Money::format($quote->amount()),
            'expires' => $values['expires'],
            'remaining' => $values['remaining'],
            'leftover' => $this->card->periodNote($preview->after),
        ]);

        $pay = static fn(PaymentMethod $method): string => CallbackData::build(self::PREFIX, $subscription->id, $from, $method->id);
        $back = $from > 0 ? MenuHandler::renewalsCallback($from) : SubscriptionHandler::serviceCallback($subscription->id);
        $instead = $from > 0 ? $this->extras->buttons($subscription) : [];
        $this->screen->show($ctx, $this->screen->withNote($this->screen->priced($text, $quote, $dropped), $plan), OrderType::Renewal, $pay, $back, $instead, free: !Money::isPositive($quote->amount()), quote: $quote, checkout: $at);
    }

    /** A way to pay picked: the renewal ordered (or the open one found) and paid — unless one is under way already. */
    private function buy(Context $ctx, Subscription $subscription, int $from, int $methodId): void
    {
        $preview = $this->preview($ctx, $subscription);
        if ($preview === null) {
            return;
        }
        $method = $this->methods->payable($methodId, OrderType::Renewal);
        if ($method === null) {
            // Switched off since the checkout was drawn: the ways that are left.
            $this->checkout($ctx, $subscription, $from);

            return;
        }

        $at = self::checkoutCallback($subscription->id, $from);
        $quote = $this->screen->priceToPay($ctx, $at, $this->price($ctx, $subscription, $preview));
        if ($quote === null) {
            return;
        }
        try {
            $this->screen->buy($ctx, $method, $quote->amount(), fn(?OrderKey $key): Order => $this->orders->openRenewal($ctx->user, $subscription, $preview->plan, $key, $quote), $at);
        } catch (RenewalUnderWayException) {
            // Another tap, or the automatic renewal, started one in the same moment.
            $ctx->answer($this->texts->get(BotText::RenewUnderWay), alert: true);
        }
    }

    /** @return \Closure(?string): Quote The renewal's price for the customer with a given discount code. */
    private function price(Context $ctx, Subscription $subscription, RenewalPreview $preview): \Closure
    {
        return fn(?string $code): Quote => $code === null ? $preview->quote : $this->pricing->renewal($ctx->user, $subscription, $preview->plan, $code);
    }

    /** The customer's service, «بازگشت» leading where the renewal was opened from when it is not there. */
    private function service(Context $ctx, int $id, int $from): ?Subscription
    {
        return $this->services->find($ctx, $id, $from > 0 ? MenuHandler::renewalsCallback($from) : MenuHandler::subscriptionsCallback(1));
    }

    /**
     * The renewal before it is paid; null (with a popup saying why) while the service may not be renewed now, or a renewal of
     * it is under way (its receipt with support, or paid and being delivered).
     */
    private function preview(Context $ctx, Subscription $subscription): ?RenewalPreview
    {
        $preview = $this->renewals->preview($subscription);
        if ($preview === null) {
            $ctx->answer($this->texts->get(BotText::RenewUnavailable), alert: true);

            return null;
        }
        if ($this->orders->renewalUnderWay($subscription)) {
            $ctx->answer($this->texts->get(BotText::RenewUnderWay), alert: true);

            return null;
        }

        return $preview;
    }
}
