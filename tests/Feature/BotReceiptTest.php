<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\ReceiptHandler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\CallbackData;
use App\Support\Persian;
use GuzzleHttp\Psr7\Response;
use Tests\BotTestCase;

/**
 * The receipt of a card transfer in the bot: a picture and nothing else — a photo, or a picture sent as a file and
 * judged by its bytes —, kept as the payment's receipt with what the customer wrote under it and the message every
 * verdict replies to; the customer learns what comes of it and, when the method has a review window, the longest wait
 * (never that the timer accepts it). Taken once: a second picture in the same moment changes nothing, and a payment
 * closed in the same moment refuses it. A reminder's «ارسال رسید» waits for the picture again.
 */
final class BotReceiptTest extends BotTestCase
{
    /** A PNG of one pixel: a picture by its bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->sellingServer();
        $this->plan = $this->plan(on: $this->server);
        $this->customer();
    }

    public function testAPhotoIsTheReceiptAndTheCustomerLearnsTheLongestWait(): void
    {
        $payment = $this->transferTo($this->cardMethod(autoApproveAfter: 90));

        $this->send($this->message(null, ['message_id' => 4321, 'photo' => [['file_id' => 'small'], ['file_id' => 'BIG-FILE-ID']], 'caption' => 'واریز شد']));

        self::assertSame([self::text(BotText::ReceiptReceivedTimed, ['outcome' => self::text(BotText::ReceiptOutcomeSubscription), 'wait' => Persian::minutes(90)])], $this->said());
        self::assertArrayNotHasKey('reply_markup', $this->params(0), 'the text alone, no keyboard');
        $payment->refresh();
        self::assertSame(PaymentStatus::AwaitingReview, $payment->status);
        self::assertSame(['BIG-FILE-ID', null, 'واریز شد', 4321], [$payment->receipt_file_id, $payment->receipt_name, $payment->receipt_note, $payment->receipt_message_id], 'the largest size, the words under it, and the message every verdict replies to');
        self::assertNotNull($payment->receipt_at);

        // The receipt is in: words now are no answer to it.
        $this->send($this->message('salam'));
        self::assertNotContains(self::text(BotText::ReceiptWaiting), $this->said());
    }

    public function testWithoutAReviewWindowTheCustomerIsToldSupportApprovesIt(): void
    {
        $this->transferTo($this->cardMethod());

        $this->send($this->message(null, ['photo' => [['file_id' => 'r-1']]]));

        self::assertSame([self::text(BotText::ReceiptReceived, ['outcome' => self::text(BotText::ReceiptOutcomeSubscription)])], $this->said());
    }

    public function testWordsAreAnsweredWithTheRequestForThePicture(): void
    {
        $payment = $this->transferTo($this->cardMethod());

        $this->send($this->message('واریز کردم'));

        self::assertSame([self::text(BotText::ReceiptWaiting)], $this->said());
        $this->send($this->message(null, ['photo' => [['file_id' => 'r-1']]]));
        self::assertSame('r-1', $payment->refresh()->receipt_file_id, 'still awaited');
    }

    public function testAnythingButAPictureIsRefusedAndThePictureIsStillAwaited(): void
    {
        $payment = $this->transferTo($this->cardMethod());

        foreach ([
            'a PDF' => ['document' => ['file_id' => 'pdf-1', 'file_name' => 'receipt.pdf', 'mime_type' => 'application/pdf']],
            'a picture too big to be a receipt' => ['document' => ['file_id' => 'big-1', 'file_name' => 'scan.png', 'mime_type' => 'image/png', 'file_size' => 11 * 1024 * 1024]],
            'a video' => ['video' => ['file_id' => 'vid-1']],
            'a sticker' => ['sticker' => ['file_id' => 'st-1']],
        ] as $what => $sent) {
            $this->send($this->message(null, $sent));

            self::assertSame([self::text(BotText::ReceiptNotImage)], $this->said(), $what);
            self::assertSame(['sendMessage'], $this->calls(), "{$what}: refused without being downloaded");
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $this->send($this->message(null, ['photo' => [['file_id' => 'photo-1']]]));
        self::assertSame(PaymentStatus::AwaitingReview, $payment->refresh()->status, 'the receipt was still awaited');
    }

    public function testAPictureSentAsAFileIsJudgedByItsBytes(): void
    {
        $payment = $this->transferTo($this->cardMethod());

        // It says it is a picture, but it is a web page: refused.
        $this->telegram()->reply(['file_path' => 'documents/a.jpg']);
        $this->telegram()->raw(new Response(200, [], '<html><script>alert(1)</script></html>'));
        $this->send($this->message(null, ['document' => ['file_id' => 'html-1', 'file_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 40]]));
        self::assertSame(['getFile', 'a.jpg', 'sendMessage'], $this->calls(), 'its bytes fetched and looked at');
        self::assertSame([self::text(BotText::ReceiptNotImage)], $this->said());
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        // An update that says a file is small (one posted to an agent's own webhook says what it likes): Telegram's word on
        // its size is the one that counts, and nothing that large is downloaded.
        $this->telegram()->reply(['file_path' => 'documents/huge.png', 'file_size' => 15 * 1024 * 1024]);
        $this->send($this->message(null, ['document' => ['file_id' => 'huge-1', 'file_name' => 'b.png', 'mime_type' => 'image/png', 'file_size' => 68]]));
        self::assertSame(['getFile', 'sendMessage'], $this->calls(), 'asked about, not downloaded');
        self::assertSame([self::text(BotText::ReceiptNotImage)], $this->said());

        // A real PNG sent as a file is a receipt, under its own name.
        $this->telegram()->reply(['file_path' => 'documents/r.png']);
        $this->telegram()->raw(new Response(200, [], (string) base64_decode(self::PNG, true)));
        $this->send($this->message(null, ['document' => ['file_id' => 'png-1', 'file_name' => 'رسید.png', 'mime_type' => 'image/png', 'file_size' => 68]]));
        $payment->refresh();
        self::assertSame(PaymentStatus::AwaitingReview, $payment->status);
        self::assertSame(['png-1', 'رسید.png'], [$payment->receipt_file_id, $payment->receipt_name]);
    }

    public function testASecondPictureInTheSameMomentChangesNothing(): void
    {
        $payment = $this->transferTo($this->cardMethod());

        // An album: its other picture, served by another process this very moment, is taken between this one reading the
        // payment and writing it.
        $this->whileThePaymentIsRead(fn(Payment $read) => $this->service(PaymentService::class)->submitReceipt($read, 'first-picture', null, null, 4320));

        self::assertSame([], $this->calls(), 'the first picture had its answer; this one adds nothing');
        $payment->refresh();
        self::assertSame(PaymentStatus::AwaitingReview, $payment->status);
        self::assertSame(['first-picture', 4320], [$payment->receipt_file_id, $payment->receipt_message_id]);
    }

    public function testAReceiptThatMeetsACancelInTheSameMomentFindsThePaymentClosed(): void
    {
        $payment = $this->transferTo($this->cardMethod());

        // Support cancels the payment on the screen between the bot reading it and taking the picture.
        $this->whileThePaymentIsRead(fn(Payment $read) => $this->service(PaymentService::class)->cancel($read, 'root', 'سفارش تکراری بود'));

        self::assertContains(self::text(BotText::OrderNotPending), $this->said());
        $payment->refresh();
        self::assertSame([PaymentStatus::Cancelled, 'سفارش تکراری بود', null], [$payment->status, $payment->note, $payment->receipt_file_id], 'the cancel stands, with its note');
    }

    public function testTheRemindersSendReceiptWaitsForThePictureAgainWhileThePaymentDoes(): void
    {
        $payment = $this->transferTo($this->cardMethod());
        $this->send($this->message('/start'));

        $this->send($this->tap(ReceiptHandler::stateFor($payment)));
        self::assertSame([self::text(BotText::ReceiptWaiting)], $this->said());
        $this->send($this->message(null, ['photo' => [['file_id' => 'late-1']]]));
        self::assertSame('late-1', $payment->refresh()->receipt_file_id);

        $this->send($this->tap(ReceiptHandler::stateFor($payment)));
        self::assertContains(self::text(BotText::OrderNotPending), $this->said(), 'the receipt is with support now');
    }

    /** The customer picked the card at the plan's checkout: its order and payment are made, and the receipt awaited. */
    private function transferTo(PaymentMethod $card): Payment
    {
        $this->send($this->tap(CallbackData::build(PurchaseHandler::serverCallback($this->plan->id, $this->server->id), $card->id)));

        return Payment::query()->sole();
    }

    /**
     * The customer sends a photo of the receipt, and another process's `$move` happens on the payment's row the moment
     * the bot has read it — before it writes.
     *
     * @param \Closure(Payment): mixed $move
     */
    private function whileThePaymentIsRead(\Closure $move): void
    {
        $moved = false;
        $this->whileListening('eloquent.retrieved: ' . Payment::class, static function (Payment $read) use (&$moved, $move): void {
            if (!$moved) {
                $moved = true;
                $move(Payment::query()->with('order')->findOrFail($read->id));
            }
        }, fn() => $this->send($this->message(null, ['message_id' => 4321, 'photo' => [['file_id' => 'this-picture']]])));
    }
}
