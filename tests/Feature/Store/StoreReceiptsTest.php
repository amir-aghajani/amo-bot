<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ErrorHandler;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Tasks\ExpireOrdersTask;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Tasks\AutoApproveReceiptsTask;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerReceipts;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\ReceiptReview;
use App\Modules\Telegram\Reports\ReportSender;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerPictures;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * A card transfer's receipt, uploaded from the shop's website: taken as the bot takes one — the customer's own payment
 * while it awaits its receipt, a picture by its bytes, at most the bot's limit —, kept among the shop's uploads under a
 * name of its own, and reviewed the same way: the same compare-and-swap (an album's second picture changes nothing), the
 * same report to the group — the picture sent as a photo —, the panel's own receipt screen; but by support alone, never
 * the method's review window. A customer uploads ten an hour at most. It goes when its order expires.
 */
final class StoreReceiptsTest extends HttpTestCase
{
    /** A PNG of one pixel: a picture by its bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Website $website;

    private User $customer;

    private Server $server;

    private Plan $plan;

    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->customer = $this->customer(['username' => 'ali']);
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan([], $this->server);
        $this->card = $this->cardMethod(autoApproveAfter: 30);
        $this->bearer($this->customerSession($this->customer));
    }

    public function testAReceiptIsTakenAsTheBotTakesOneAndGoesToTheGroupAsAPhoto(): void
    {
        $this->reportGroup();
        $payment = $this->transfer();

        $response = $this->sendReceipt($payment, "C:\\fakepath\\رسید\u{202E}gpj.png", ['note' => 'از کارت پدرم']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $order = $this->decode($response)['order'];
        self::assertSame(['pending', 'awaiting_review', '2026-10-07T12:00:00+00:00'], [$order['status'], $order['payments'][0]['status'], $order['payments'][0]['receipt']['sent_at']]);
        $payment->refresh();
        self::assertSame([PaymentStatus::AwaitingReview, null, null, 'رسیدgpj.png', 'از کارت پدرم'], [$payment->status, $payment->receipt_file_id, $payment->receipt_message_id, $payment->receipt_name, $payment->receipt_note], "the device's name its last part, without what turns it around");
        self::assertMatchesRegularExpression("/^1-{$payment->id}-[0-9a-f]{16}\\.png$/", (string) $payment->receipt_path, 'a name of the shop\'s own');
        $kept = (string) file_get_contents($this->receipts() . '/' . $payment->receipt_path);
        self::assertSame([1, 1, 'image/png'], self::imageOf($kept), 'the picture as the shop keeps it');

        self::assertSame(1, $this->service(ReportSender::class)->flush());
        self::assertSame(['sendPhoto'], $this->telegram()->calls());
        $photo = $this->telegram()->params(0);
        self::assertSame($kept, $this->telegram()->files(0)['photo'] ?? null, 'the picture, by its bytes');
        self::assertSame([(string) self::REPORT_GROUP, 'HTML'], [$photo['chat_id'], $photo['parse_mode']]);
        self::assertStringContainsString("🧾 <b>رسید جدید</b> · پرداخت #{$payment->id}", $photo['caption']);
        self::assertStringContainsString('📝 توضیح مشتری: از کارت پدرم', $photo['caption']);
        self::assertStringContainsString('رسیدی که از وب‌سایت می‌رسد خودکار تایید نمی‌شود', $photo['caption'], "the method's review window is not this receipt's");
        self::assertStringNotContainsString('۳۰ دقیقه', $photo['caption']);
        self::assertSame(ReceiptReview::buttons($payment->id), FakeTelegram::markupOf($photo)['inline_keyboard'] ?? null, "the bot admins' buttons under it");

        // Support approves it on the panel: the service is delivered, the customer told, the verdict a reply to the photo.
        $this->telegram()->reset();
        $this->loginAsAdmin();
        self::assertSame(200, $this->postJson("/api/admin/payments/{$payment->id}/approve")->getStatusCode());
        self::assertSame(OrderStatus::Fulfilled, $payment->order->refresh()->status);
        self::assertCount(1, FakeProvider::$created);
        self::assertCount(1, $this->telegram()->sentTo(self::TELEGRAM_ID), 'the service, in the bot: they have Telegram');
        self::assertNull($this->telegram()->replyTarget(0), 'a plain message: no receipt of theirs in the chat to reply to');
        $this->telegram()->reset();
        $this->service(ReportSender::class)->flush();
        self::assertSame(['message_id' => 101, 'allow_sending_without_reply' => true], json_decode($this->telegram()->params(0)['reply_parameters'] ?? '', true), 'the verdict under the photo');
    }

    public function testThePanelServesAnUploadedReceiptAndSaysSoOnceItIsGone(): void
    {
        $payment = $this->transfer();
        $this->sendReceipt($payment, 'receipt.png');
        $this->loginAsAdmin();

        $response = $this->get("/api/admin/payments/{$payment->id}/receipt");

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'), 'a picture the panel shows');
        self::assertStringStartsWith('inline', $response->getHeaderLine('Content-Disposition'));
        self::assertSame(file_get_contents($this->receipts() . '/' . $payment->refresh()->receipt_path), (string) $response->getBody(), 'the picture as the shop keeps it');
        self::assertSame([], $this->telegram()->calls(), 'from the shop\'s own files, not Telegram');
        $row = $this->decode($this->get("/api/admin/payments/{$payment->id}"))['payment'];
        self::assertSame(['name' => 'receipt.png', 'note' => null, 'sent_at' => '2026-10-07T12:00:00+00:00'], $row['receipt']);

        unlink($this->receipts() . '/' . $payment->refresh()->receipt_path);
        $response = $this->get("/api/admin/payments/{$payment->id}/receipt");
        self::assertSame([404, 'فایل این رسید دیگر روی سرور نیست.'], [$response->getStatusCode(), $this->decode($response)['message']]);
    }

    public function testTheReviewWindowLeavesAnUploadedReceiptToSupport(): void
    {
        $uploaded = $this->transfer();
        $this->sendReceipt($uploaded, 'receipt.png');
        // A receipt of the same method sent in the bot, in the same window: that one the window accepts.
        $fromTheBot = $this->receipt($this->cardPayment($this->topUpOrder($this->customer, '70000.00'), $this->card));

        Carbon::setTestNow(now()->addMinutes(31));
        $this->service(AutoApproveReceiptsTask::class)->run();

        self::assertSame([PaymentStatus::AwaitingReview, OrderStatus::Pending], [$uploaded->refresh()->status, $uploaded->order->status], 'an upload waits for support, whatever the window');
        self::assertTrue($fromTheBot->refresh()->wasAutoApproved());

        $this->loginAsAdmin();
        self::assertSame(200, $this->postJson("/api/admin/payments/{$uploaded->id}/approve")->getStatusCode());
        self::assertSame(OrderStatus::Fulfilled, $uploaded->order->refresh()->status);
    }

    public function testACustomerUploadsTenReceiptsAnHourAtMost(): void
    {
        $payments = [];
        foreach (range(1, CustomerPictures::UPLOADS + 1) as $n) {
            $payments[] = $this->cardPayment($this->topUpOrder($this->customer, ($n * 10000) . '.00'), $this->card);
        }
        // A refused one counts for nothing: nothing of it is kept.
        $this->sendReceipt($payments[0], 'receipt.png', bytes: 'just some words');
        foreach (array_slice($payments, 0, CustomerPictures::UPLOADS) as $payment) {
            self::assertSame(200, $this->sendReceipt($payment, 'receipt.png')->getStatusCode());
        }

        $response = $this->sendReceipt($payments[CustomerPictures::UPLOADS], 'receipt.png');

        self::assertSame([429, '3600'], [$response->getStatusCode(), $response->getHeaderLine('Retry-After')]);
        self::assertSame('تصویر زیادی فرستاده‌اید؛ ۶۰ دقیقه دیگر دوباره امتحان کنید.', $this->decode($response)['message']);
        self::assertSame(PaymentStatus::Pending, $payments[CustomerPictures::UPLOADS]->refresh()->status);
        self::assertCount(CustomerPictures::UPLOADS, glob($this->receipts() . '/*') ?: [], 'the last one not kept');

        Carbon::setTestNow(now()->addHour());
        self::assertSame(200, $this->sendReceipt($payments[CustomerPictures::UPLOADS], 'receipt.png')->getStatusCode(), 'the hour over');
    }

    public function testAReceiptIsAPictureByItsBytesAtMostTheBotsLimit(): void
    {
        $payment = $this->transfer();
        $refusals = [
            'words named a picture' => $this->sendReceipt($payment, 'receipt.png', bytes: 'just some words'),
            'a GIF' => $this->sendReceipt($payment, 'receipt.gif', bytes: 'GIF89a' . str_repeat("\0", 32)),
            'past 10 MB' => $this->sendReceipt($payment, 'receipt.png', bytes: base64_decode(self::PNG) . str_repeat("\0", 10 * 1024 * 1024)),
        ];

        foreach ($refusals as $why => $response) {
            self::assertSame(422, $response->getStatusCode(), $why);
            self::assertArrayHasKey('file', $this->decode($response)['errors'], $why);
        }
        self::assertSame('حجم تصویر رسید حداکثر ۱۰ مگابایت می‌تواند باشد.', $this->decode($refusals['past 10 MB'])['errors']['file'][0]);
        self::assertSame([PaymentStatus::Pending, null], [$payment->refresh()->status, $payment->receipt_path]);
        self::assertSame([], glob($this->receipts() . '/*'), 'nothing kept');
    }

    public function testNoFileOrOnePhpDidNotTakeWholeIsSaidSo(): void
    {
        $payment = $this->transfer();
        $receipts = $this->service(CustomerReceipts::class);
        $refused = function (?UploadedFileInterface $file) use ($receipts, $payment): string {
            try {
                $receipts->upload($this->customer, $payment->id, $file, []);
            } catch (ValidationException $e) {
                return $e->errors()['file'][0] ?? '';
            }

            return self::fail('A receipt was taken.');
        };
        $failed = static fn(int $error): UploadedFile => new UploadedFile((new StreamFactory())->createStream(''), 'receipt.png', 'image/png', 0, $error);

        self::assertSame('تصویر رسید را بفرستید.', $refused(null));
        self::assertSame('تصویر رسید را بفرستید.', $refused($failed(UPLOAD_ERR_NO_FILE)));
        self::assertSame('فایل بزرگ‌تر از حد مجاز سرور است.', $refused($failed(UPLOAD_ERR_INI_SIZE)), "past the host's own limit, which may be below ours");
        self::assertSame('آپلود فایل ناتمام ماند؛ دوباره تلاش کنید.', $refused($failed(UPLOAD_ERR_PARTIAL)));
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testATooLongNoteAndAFileThatIsNoPictureAreRefusedTogether(): void
    {
        $payment = $this->transfer();

        $response = $this->sendReceipt($payment, 'receipt.txt', ['note' => str_repeat('ن', PaymentService::RECEIPT_NOTE_MAX + 1)], 'words');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['note', 'file'], array_keys($this->decode($response)['errors']));

        $response = $this->sendReceipt($payment, 'receipt.png', ['note' => str_repeat('ن', PaymentService::RECEIPT_NOTE_MAX + 1)]);
        self::assertSame([422, ['توضیح حداکثر 1024 کاراکتر است.']], [$response->getStatusCode(), $this->decode($response)['errors']['note'] ?? null]);
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testAnotherCustomersPaymentIsNotThere(): void
    {
        $theirs = $this->cardPayment($this->purchaseOrder($this->customer(['telegram_id' => 7272]), $this->plan, $this->server), $this->card);

        $response = $this->sendReceipt($theirs, 'receipt.png');

        self::assertSame([404, ErrorHandler::NOT_FOUND], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame(PaymentStatus::Pending, $theirs->refresh()->status);
    }

    public function testAPaymentThatAwaitsNoReceiptRefusesOneInTheWordsOfItsState(): void
    {
        $actions = $this->service(PaymentActions::class);
        $inReview = $this->transfer('in-review');
        $this->sendReceipt($inReview, 'receipt.png');
        $refused = $this->cardPayment($this->topUpOrder($this->customer, '50000.00'), $this->card);
        $this->receipt($refused);
        $actions->reject($refused, $this->panelActor(), 'ناخوانا');
        $cancelled = $this->cardPayment($this->topUpOrder($this->customer, '60000.00'), $this->card);
        $actions->cancel($cancelled, $this->panelActor(), null);
        $this->wallet($this->customer, '500000');
        $fromTheWallet = $this->buy($this->customer, $this->plan, $this->server)->payments()->sole();

        $expected = [
            'رسید این پرداخت رسیده و در انتظار بررسی است.' => $inReview,
            'رسید این پرداخت رد شده است.' => $refused,
            'این پرداخت لغو شده است.' => $cancelled,
            'این پرداخت تایید شده است.' => $fromTheWallet,
        ];
        foreach ($expected as $words => $payment) {
            $response = $this->sendReceipt($payment, 'receipt.png');
            self::assertSame([422, [$words]], [$response->getStatusCode(), $this->decode($response)['errors']['status'] ?? null], $words);
        }
        self::assertCount(1, glob($this->receipts() . '/*') ?: [], 'the first receipt alone was kept');
    }

    public function testAnAlbumsSecondPictureInTheSameMomentChangesNothing(): void
    {
        $this->reportGroup();
        $payment = $this->transfer();

        // Its other picture, sent the same moment, is taken between this one reading the payment and writing it.
        $other = null;
        $response = $this->whileListening('eloquent.retrieved: ' . Payment::class, function () use (&$other, $payment): void {
            if ($other === null) {
                $other = false;
                $other = $this->sendReceipt($payment, 'first.png');
            }
        }, fn(): ResponseInterface => $this->sendReceipt($payment, 'second.png'));

        self::assertInstanceOf(ResponseInterface::class, $other);
        self::assertSame(200, $other->getStatusCode(), 'the first picture is the receipt');
        self::assertSame([422, ['رسید این پرداخت رسیده و در انتظار بررسی است.']], [$response->getStatusCode(), $this->decode($response)['errors']['status'] ?? null]);
        $payment->refresh();
        self::assertSame('first.png', $payment->receipt_name);
        self::assertSame([$this->receipts() . '/' . $payment->receipt_path], glob($this->receipts() . '/*'), 'the second picture was not kept');
        self::assertSame(1, ReportMessage::query()->count(), 'one receipt for the group');
    }

    public function testAnExpiredOrdersRefusedReceiptGoesWithIt(): void
    {
        $payment = $this->transfer();
        $this->sendReceipt($payment, 'receipt.png');
        $this->service(PaymentActions::class)->reject($payment->refresh(), $this->panelActor(), 'ناخوانا');
        $path = $this->receipts() . '/' . $payment->receipt_path;
        self::assertFileExists($path, 'a refused receipt stays: the customer may pay again');

        Carbon::setTestNow(now()->addHours(ExpireOrdersTask::EXPIRE_HOURS)->addMinute());
        $this->service(ExpireOrdersTask::class)->run();

        self::assertSame([PaymentStatus::Cancelled, 'ناخوانا'], [$payment->refresh()->status, $payment->note], 'its refusal\'s reason kept');
        self::assertFileDoesNotExist($path, 'nobody reviews a walked-away order\'s receipt again');
        $this->loginAsAdmin();
        $response = $this->get("/api/admin/payments/{$payment->id}/receipt");
        self::assertSame([404, 'فایل این رسید دیگر روی سرور نیست.'], [$response->getStatusCode(), $this->decode($response)['message']], 'the payment still says one was sent');
    }

    public function testAReceiptTelegramTurnsDownAsAPhotoOrOneGoneGoesToTheGroupAsText(): void
    {
        $this->reportGroup();
        $first = $this->transfer('first');
        $this->sendReceipt($first, 'receipt.png');
        $this->telegram()->fail(400, 'Bad Request: PHOTO_INVALID_DIMENSIONS');

        self::assertSame(1, $this->service(ReportSender::class)->flush());
        self::assertSame(['sendPhoto', 'sendMessage'], $this->telegram()->calls());
        $text = $this->telegram()->params(1);
        self::assertStringContainsString('تصویر فرستاده نشد', $text['text']);
        self::assertCount(2, FakeTelegram::markupOf($text)['inline_keyboard'][0] ?? [], 'the buttons come with the words');

        $second = $this->cardPayment($this->topUpOrder($this->customer, '70000.00'), $this->card);
        $this->sendReceipt($second, 'receipt.png');
        unlink($this->receipts() . '/' . $second->refresh()->receipt_path);
        $this->telegram()->reset();

        self::assertSame(1, $this->service(ReportSender::class)->flush());
        self::assertSame(['sendMessage'], $this->telegram()->calls());
        self::assertStringContainsString('فایل این تصویر روی سرور پیدا نشد', $this->telegram()->params(0)['text']);
    }

    public function testACustomerWithoutTelegramIsToldNothingInTheBot(): void
    {
        $sara = $this->webCustomer();
        $this->bearer($this->customerSession($sara));
        $payment = $this->transfer();
        $this->sendReceipt($payment, 'receipt.png');

        $this->loginAsAdmin();
        self::assertSame(200, $this->postJson("/api/admin/payments/{$payment->id}/approve")->getStatusCode());

        self::assertSame(OrderStatus::Fulfilled, $payment->order->refresh()->status);
        self::assertSame([], $this->telegram()->calls(), 'no Telegram account: their website says it');
    }

    /** The checkout of the plan by card, as the website makes it: the payment the receipt is for. */
    private function transfer(string $key = 'card-1'): Payment
    {
        $response = $this->send('POST', $this->storeApi($this->website, '/orders'), ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'method_id' => $this->card->id], ['Idempotency-Key' => $key]);
        $id = $this->decode($response)['checkout']['transfer']['payment_id'] ?? self::fail((string) $response->getBody());

        return Payment::query()->findOrFail($id);
    }

    /** @param array<string, string> $fields */
    private function sendReceipt(Payment $payment, string $name, array $fields = [], ?string $bytes = null): ResponseInterface
    {
        return $this->upload($this->storeApi($this->website, "/payments/{$payment->id}/receipt"), 'file', $name, $bytes ?? (string) base64_decode(self::PNG), $fields);
    }

    /** Where the uploads are kept: the run's own folder. */
    private function receipts(): string
    {
        return (string) $this->app()->container()->get('receipts.path');
    }
}
