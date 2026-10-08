<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\RenewalUnderWayException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Subscriptions\DTO\RenewalPreview;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Support\Money;

/**
 * «♻️ تمدید سرویس»: one of the customer's services renewed on its plan, through the checkout every purchase in the bot
 * goes through (CheckoutScreen):
 *   renew:{service}:{from}          → the checkout: the plan's term, traffic and price, and the service as the renewal
 *                                     would leave it (CustomerRenewal::preview(), the website's too) — nothing ordered
 *                                     yet; «بازگشت» to where it was opened: the service's screen (`from` 0), or that page
 *                                     of the menu's list
 *   renew:{service}:{from}:{method} → pay with that method: the renewal ordered now, or the open one found
 *                                     (OrderService::openRenewal())
 * The service's own screen opens the checkout too (`sub:{id}:renew`), and so does a payment reminder's «پرداخت دوباره»
 * of a renewal. Only the customer's own services answer. One they may not renew now (CustomerRenewal), or one whose
 * renewal is already under way, is said so in a popup on the button pressed, and nothing is ordered. The wallet renews it
 * at once; a card waits for its receipt — what comes of either is the renewal's own message (ServiceCard).
 */
final class RenewalHandler implements Handler
{
    public const PREFIX = 'renew:';

    public function __construct(
        private readonly CustomerRenewal $renewals,
        private readonly OrderService $orders,
        private readonly PaymentMethods $methods,
        private readonly CheckoutScreen $screen,
        private readonly ServiceCard $card,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** The checkout of the service's renewal, opened from its screen (`$from` 0) or from that page of the menu's list. */
    public static function checkoutCallback(int $subscriptionId, int $from = 0): string
    {
        return CallbackData::build(self::PREFIX, $subscriptionId, $from);
    }

    public function handle(Context $ctx): void
    {
        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];
        $from = max(0, (int) ($args[1] ?? 0));
        $subscription = Subscription::query()
            ->where('user_id', $ctx->user->id)
            ->where('status', '!=', SubscriptionStatus::Deleted->value)
            ->find((int) ($args[0] ?? 0));
        if ($subscription === null) {
            $ctx->edit($this->texts->get(BotText::ServiceNotFound), ['reply_markup' => $this->buttons->backOnly($from > 0 ? MenuHandler::renewalsCallback($from) : MenuHandler::subscriptionsCallback(1))]);

            return;
        }

        isset($args[2]) ? $this->buy($ctx, $subscription, $from, (int) $args[2]) : $this->checkout($ctx, $subscription, $from);
    }

    /**
     * The renewal's checkout: what it renews the service with, at what price, and the service as it would leave it — its
     * end and the traffic left, the traffic of the period in use that goes when that period ends (the shop not carrying
     * it) — with the ways to pay. A service that cannot be renewed now is said so in a popup instead.
     */
    public function checkout(Context $ctx, Subscription $subscription, int $from = 0): void
    {
        $preview = $this->preview($ctx, $subscription);
        if ($preview === null) {
            return;
        }

        $plan = $preview->plan;
        $values = $this->card->values($preview->after);
        $text = $this->texts->render(BotText::RenewCheckout, [
            'client' => $subscription->remote_name,
            'plan' => $plan->name,
            'duration' => Messages::duration($plan->duration_days),
            'traffic' => Messages::traffic($plan->trafficBytes()),
            'amount' => Money::format($preview->price),
            'expires' => $values['expires'],
            'remaining' => $values['remaining'],
            'leftover' => $this->card->periodNote($preview->after),
        ]);

        $pay = static fn(PaymentMethod $method): string => CallbackData::build(self::PREFIX, $subscription->id, $from, $method->id);
        $back = $from > 0 ? MenuHandler::renewalsCallback($from) : SubscriptionHandler::serviceCallback($subscription->id);
        $this->screen->show($ctx, $text, OrderType::Renewal, $pay, $back);
    }

    /**
     * A way to pay picked: the renewal ordered — or the open one found — and paid. A renewal another tap or the timer
     * started in the same moment is the one under way: nothing more is ordered.
     */
    private function buy(Context $ctx, Subscription $subscription, int $from, int $methodId): void
    {
        $preview = $this->preview($ctx, $subscription);
        if ($preview === null) {
            return;
        }
        $method = $this->methods->payable($methodId, OrderType::Renewal);
        if ($method === null) {
            // Switched off (or deleted) since the checkout was shown: the ways that are left.
            $this->checkout($ctx, $subscription, $from);

            return;
        }

        try {
            $this->screen->buy($ctx, $method, $preview->price, fn(): Order => $this->orders->openRenewal($ctx->user, $subscription, $preview->plan), self::checkoutCallback($subscription->id, $from));
        } catch (RenewalUnderWayException) {
            $ctx->answer($this->texts->get(BotText::RenewUnderWay), alert: true);
        }
    }

    /**
     * The renewal before it is paid (CustomerRenewal::preview()); null — the customer told why, in a popup — while they
     * may not renew the service now, or a renewal of it is under way already (its receipt with support, or paid and
     * being delivered).
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
