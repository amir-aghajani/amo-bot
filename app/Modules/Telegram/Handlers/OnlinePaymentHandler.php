<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Security\RateLimiter;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\GatewayKind;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;

/**
 * `online:{payment}` — «🔄 بررسی پرداخت» under an online gateway's page (CheckoutScreen): the gateway is asked what came
 * of the customer's own payment (OnlinePayments::check(), which settles it and sends the notice of it), and the tap is
 * answered in a popup — paid, already paid, nothing yet, held for support, or the gateway out of reach. A page paid on
 * turns into a short line saying so, its buttons gone (the card, the copies, «پرداخت»); a page whose time ran out into
 * a word saying so, with «بازگشت» to the order's checkout («پرداخت دوباره»), where a new page is opened — or, its order
 * no longer open, into a word saying that. The gateway is asked once in ASK_SECONDS per payment: a tap sooner is told
 * nothing came yet. A payment held for support is never asked about: the customer is sent to support. Anyone else's
 * payment, or one that is no online gateway's, is answered with nothing.
 */
final class OnlinePaymentHandler implements Handler
{
    public const PREFIX = 'online:';

    /** Seconds between two asks of a payment's gateway from the bot. */
    private const ASK_SECONDS = 5;

    public function __construct(
        private readonly OnlinePayments $online,
        private readonly GatewayRegistry $gateways,
        private readonly RateLimiter $limiter,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** «🔄 بررسی پرداخت» of this payment. */
    public static function checkCallback(Payment $payment): string
    {
        return CallbackData::build(self::PREFIX, $payment->id);
    }

    public function handle(Context $ctx): void
    {
        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];
        $payment = count($args) === 1 ? Payment::query()->whereRelation('order', 'user_id', $ctx->user->id)->find((int) $args[0]) : null;
        if ($payment === null || $this->gateways->kind($payment->method->driver) !== GatewayKind::Online) {
            return;
        }
        if ($payment->status->isSettled()) {
            $ctx->answer($this->texts->get(BotText::PayOnlineAlreadyPaid), alert: true);
            $this->done($ctx, $payment);

            return;
        }
        // Left to support: its gateway is never asked again, and the customer must not pay the order again meanwhile.
        if (OnlinePayments::awaitsSupport($payment)) {
            $ctx->answer($this->texts->get(BotText::PayOnlineHeld), alert: true);

            return;
        }
        if ($this->limiter->attempt([['online-check|' . $payment->id, 1, self::ASK_SECONDS]]) > 0) {
            $ctx->answer($this->texts->get(self::waiting($payment)), alert: true);

            return;
        }

        try {
            $state = $this->online->check($payment);
        } catch (GatewayUnavailableException $e) {
            // This very ask may be what held it: the gateway's answer did not fit the payment.
            $ctx->answer($this->texts->get($e->held ? BotText::PayOnlineHeld : BotText::PayOnlineUnreachable), alert: true);

            return;
        }
        if ($state === OnlineState::Paid) {
            // The notice of what it brought comes on its own (CustomerNotifier), from whichever ask settled it.
            $ctx->answer($this->texts->get(BotText::PayOnlinePaid), alert: true);
            $this->done($ctx, $payment);

            return;
        }
        // Nothing paid, and its order closed meanwhile (paid another way, cancelled, expired): nothing to pay on that page,
        // nor a new page to open.
        if ($payment->order->refresh()->status !== OrderStatus::Pending) {
            $ctx->edit($this->texts->get(BotText::OrderNotPending), ['reply_markup' => $this->buttons->backOnly(MainMenu::HOME)]);

            return;
        }
        if ($state === OnlineState::Waiting) {
            $ctx->answer($this->texts->get(self::waiting($payment->refresh())), alert: true);

            return;
        }
        $ctx->edit(
            $this->texts->render(BotText::PayOnlineClosed, ['order' => $payment->order_id]),
            ['reply_markup' => $this->buttons->backOnly(PurchaseHandler::reopenCallback($payment->order_id))],
        );
    }

    /** The page of a paid payment: a short line saying so in its place, without the buttons to pay it again. */
    private function done(Context $ctx, Payment $payment): void
    {
        $ctx->edit($this->texts->render(BotText::PayOnlineDone, ['order' => $payment->order_id]), ['reply_markup' => ['inline_keyboard' => []]]);
    }

    /** Not paid yet: nothing came, or — money in at the gateway, under its review — not to be paid again meanwhile. */
    private static function waiting(Payment $payment): BotText
    {
        return OnlinePayments::hasMoneyIn($payment) ? BotText::PayOnlineReviewing : BotText::PayOnlineWaiting;
    }
}
