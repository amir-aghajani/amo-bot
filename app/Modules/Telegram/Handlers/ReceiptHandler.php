<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Payments\Drivers\Manual\ManualGateway;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Services\CustomerPictures;
use App\Support\Persian;

/**
 * State `receipt:{payment}`: after a card's details, the chat waits for the receipt — a picture only (a photo, or a
 * picture sent as a file, judged by its bytes) — while the payment still awaits one (PaymentService::awaitsReceipt(),
 * the website's rule too). The picture is kept by its Telegram file id for support to review; the customer is told the
 * longest they may wait when the method approves on a timer. A reminder's «ارسال رسید» is the same callback, entering
 * the state again.
 */
final class ReceiptHandler implements Handler
{
    public const PREFIX = 'receipt:';

    public function __construct(
        private readonly PaymentService $payments,
        private readonly CustomerPictures $pictures,
        private readonly MainMenu $menu,
        private readonly BotTexts $texts,
    ) {}

    /** The state that waits for this payment's receipt — and the callback that enters it again. */
    public static function stateFor(Payment $payment): string
    {
        return CallbackData::build(self::PREFIX, $payment->id);
    }

    /** What a paid order of this type brings, as a part said before it arrives («اشتراک شما فعال و همین‌جا ارسال می‌شود»). */
    public static function outcome(OrderType $type): BotText
    {
        return match ($type) {
            OrderType::WalletTopUp => BotText::ReceiptOutcomeTopup,
            OrderType::Traffic => BotText::ReceiptOutcomeTraffic,
            OrderType::Renewal => BotText::ReceiptOutcomeRenewal,
            OrderType::ExtraTraffic => BotText::ReceiptOutcomeExtraTraffic,
            OrderType::ExtraTime => BotText::ReceiptOutcomeExtraTime,
            OrderType::Purchase => BotText::ReceiptOutcomeSubscription,
        };
    }

    public function handle(Context $ctx): void
    {
        $update = $ctx->update;
        $args = $update->isCallback() ? $update->callbackArgs(self::PREFIX) : CallbackData::args((string) $ctx->session->state(), self::PREFIX);
        $payment = Payment::query()->whereRelation('order', 'user_id', $ctx->user->id)->find((int) ($args[0] ?? 0));

        if ($payment === null || !$this->payments->awaitsReceipt($payment)) {
            $this->closed($ctx);

            return;
        }
        if ($update->isCallback()) {
            $ctx->session->enter(self::stateFor($payment));
            $ctx->reply($this->texts->get(BotText::ReceiptWaiting));

            return;
        }

        $picture = $update->picture($this->pictures->isPicture(...));
        if ($picture === null) {
            // A plain message is a reminder that a receipt is awaited; anything else is the wrong kind of file.
            $ctx->reply($this->texts->get(in_array($update->contentKind(), ['text', null], true) ? BotText::ReceiptWaiting : BotText::ReceiptNotImage));

            return;
        }

        // Every word about the verdict later replies to this message.
        $taken = $this->payments->submitReceipt($payment, $picture['file_id'], $update->caption(), $picture['name'], $update->messageId());
        if (!$taken) {
            // An earlier picture (an album's) was taken first and answered, or the payment closed meanwhile.
            if ($payment->refresh()->status !== PaymentStatus::AwaitingReview) {
                $this->closed($ctx);
            }

            return;
        }

        $ctx->session->clear();
        $window = ManualGateway::reviewWindow($payment->method);
        $outcome = $this->texts->part(self::outcome($payment->order->type));
        $ctx->reply($window > 0
            ? $this->texts->render(BotText::ReceiptReceivedTimed, ['outcome' => $outcome, 'wait' => Persian::minutes($window)])
            : $this->texts->render(BotText::ReceiptReceived, ['outcome' => $outcome]));
    }

    /** The payment no longer waits for a receipt (paid, cancelled, expired): the flow ends at the menu. */
    private function closed(Context $ctx): void
    {
        $ctx->session->clear();
        $this->menu->show($ctx, $this->texts->get(BotText::OrderNotPending));
    }
}
