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
 * Conversation state `receipt:{payment}`: the customer was shown the card of a card-to-card payment and is expected to
 * send the receipt — a picture and nothing else: a Telegram photo, or a picture sent as a file, judged by its bytes
 * (CustomerPictures::isPicture(), the shop's one rule for a picture it is sent) — while the payment awaits one (PaymentService::awaitsReceipt(), the rule the website's upload
 * keeps too). The Telegram file id is kept as the receipt, which support opens from the payments screen and the report
 * group; it is approved there — or by the timer, when the method has a review window (the customer is told the longest
 * they will wait). The same `receipt:{payment}` is the callback of a reminder's «ارسال رسید», which enters the state
 * again.
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

    /**
     * What a paid order of this type brings, said before it arrives — a receipt taken, a payment whose delivery runs
     * elsewhere (CheckoutScreen): «اشتراک شما فعال و همین‌جا ارسال می‌شود».
     */
    public static function outcome(OrderType $type): BotText
    {
        return match ($type) {
            OrderType::WalletTopUp => BotText::ReceiptOutcomeTopup,
            OrderType::Traffic => BotText::ReceiptOutcomeTraffic,
            OrderType::Renewal => BotText::ReceiptOutcomeRenewal,
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

        $document = $update->document();
        $file = match ($update->contentKind()) {
            'photo' => $update->photo(),
            'document' => $document !== null && $this->pictures->isPicture($document) ? $document : false,
            'text', null => null,
            default => false,
        };
        if ($file === null) {
            $ctx->reply($this->texts->get(BotText::ReceiptWaiting));

            return;
        }
        if ($file === false) {
            $ctx->reply($this->texts->get(BotText::ReceiptNotImage));

            return;
        }

        $caption = ($update->message() ?? [])['caption'] ?? null;
        $taken = $this->payments->submitReceipt(
            $payment,
            (string) ($file['file_id'] ?? ''),
            is_string($caption) ? $caption : null,
            isset($document['file_name']) ? (string) $document['file_name'] : null,
            $update->messageId(), // every word about the verdict replies to this message
        );
        if (!$taken) {
            // A picture before this one came first — and had its answer —, or the payment closed meanwhile.
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

    /** The payment is not waiting for a receipt any more (paid, cancelled, expired): the flow ends at the menu. */
    private function closed(Context $ctx): void
    {
        $ctx->session->clear();
        $this->menu->show($ctx, $this->texts->get(BotText::OrderNotPending));
    }
}
