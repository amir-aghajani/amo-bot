<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Urls;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Tasks\ExpireOrdersTask;
use App\Modules\Payments\Deposits\Services\Deposits;
use App\Modules\Payments\Deposits\Services\DepositSettings;
use App\Modules\Payments\Enums\DepositStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Deposit;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentCards;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Monolog\LogRecord;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * «تایید خودکار واریز»: ForApp forwards the bank's deposit SMS; while the shop listens, every card payment asks a sum of
 * its own, and a deposit of that sum approves it — its service delivered, the customer told. Nothing is approved on a
 * guess: a sum no payment asks, or two ask, is support's, said in the report group. Each SMS is taken once.
 */
final class DepositWebhookTest extends HttpTestCase
{
    private Server $server;
    private Plan $plan;
    private PaymentMethod $card;
    private User $ali;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-14 10:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->reportGroup();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '150000.00'], $this->server);
        $this->card = $this->cardMethod();
        $this->ali = $this->customer(['username' => 'ali']);
        $settings = $this->service(DepositSettings::class);
        $settings->enable(true);
        $this->key = $settings->key();
    }

    public function testListeningEveryCardPaymentAsksASumOfItsOwn(): void
    {
        $sums = [];
        foreach (range(1, 5) as $n) {
            $sums[] = $this->openCardPayment($this->customer(['telegram_id' => 9000 + $n]))->amount;
        }

        self::assertCount(5, array_unique($sums), 'no two open card payments ask the same sum');
        foreach ($sums as $sum) {
            self::assertGreaterThan(150000, (float) $sum);
            self::assertLessThanOrEqual(150000 + PaymentCards::VERIFIED_EXTRA_MAX, (float) $sum);
            self::assertSame(0.0, fmod((float) $sum, 1.0), 'whole Toman');
        }

        $this->service(DepositSettings::class)->enable(false);
        self::assertSame('150000.00', $this->openCardPayment($this->customer(['telegram_id' => 9100]))->amount, 'not listening: the price itself');
    }

    public function testADepositOfThePaymentsSumApprovesItAndDeliversTheService(): void
    {
        $payment = $this->openCardPayment($this->ali);

        $response = $this->forward(['id' => 'sms-1', 'amount' => self::rial($payment), 'is_deposit' => true, 'bank' => 'ملت']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['received' => true, 'status' => 'matched', 'payment_id' => $payment->id], $this->decode($response));
        $payment->refresh();
        self::assertSame([PaymentStatus::Paid, Deposits::REVIEWER], [$payment->status, $payment->reviewer]);
        self::assertSame(OrderStatus::Fulfilled, $payment->order->refresh()->status, 'the service delivered');
        self::assertCount(1, FakeProvider::$created);
        self::assertNotEmpty($this->telegram()->sentTo(self::TELEGRAM_ID), 'the customer told');
        self::assertStringContainsString('✅ تایید با پیامک بانک', (string) ReportMessage::query()->where('topic', Topic::Purchases->value)->latest('id')->value('text'));
        self::assertSame(Deposits::REVIEWER, $this->service(Reviewers::class)->shared($payment->reviewer), 'shown as it is to an agent or a website\'s admin: no person behind it to hide');

        // ForApp sends it again (it did not hear the answer): what came of it the first time, nothing twice.
        $again = $this->forward(['id' => 'sms-1', 'amount' => self::rial($payment), 'is_deposit' => true]);
        self::assertSame('matched', $this->decode($again)['status']);
        self::assertSame(1, Deposit::query()->count());
        self::assertCount(1, FakeProvider::$created);
    }

    public function testAReceiptWaitingForReviewIsApprovedByTheSmsAndSaidSo(): void
    {
        $payment = $this->openCardPayment($this->ali);
        $this->receipt($payment);

        $this->forward(['id' => 'sms-2', 'amount' => self::rial($payment), 'is_deposit' => true]);

        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        self::assertStringContainsString('با پیامک واریز بانک خودکار تایید شد', (string) ReportMessage::query()->where('topic', Topic::Receipts->value)->latest('id')->value('text'));
    }

    public function testASumNoPaymentAsksApprovesNothingAndTellsSupport(): void
    {
        $payment = $this->openCardPayment($this->ali);

        $response = $this->forward(['id' => 'sms-3', 'amount' => 1500000, 'is_deposit' => true, 'body' => "بانک ملت\nواریز:1,500,000"]);

        self::assertSame('unmatched', $this->decode($response)['status']);
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $report = (string) ReportMessage::query()->where('topic', Topic::Receipts->value)->latest('id')->value('text');
        self::assertStringContainsString('💰 <b>واریز بدون پرداخت</b> · ۱۵۰٬۰۰۰ تومان', $report);
        self::assertStringContainsString('واریز:1,500,000', $report);
    }

    public function testAPaymentApprovedBeforeItsSmsCameIsOnlyConfirmedByIt(): void
    {
        $payment = $this->openCardPayment($this->ali);
        $this->receipt($payment);
        $this->service(PaymentService::class)->approve($payment, 'admin', receiptOnly: true);
        $reports = ReportMessage::query()->where('topic', Topic::Receipts->value)->count();

        $response = $this->forward(['id' => 'sms-12', 'amount' => self::rial($payment), 'is_deposit' => true]);

        self::assertSame(['received' => true, 'status' => 'matched', 'payment_id' => $payment->id], $this->decode($response));
        self::assertSame('admin', $payment->refresh()->reviewer, 'approved once, by whom it was');
        self::assertSame($reports, ReportMessage::query()->where('topic', Topic::Receipts->value)->count(), 'nothing for support');

        // Its sum again: that payment is confirmed already — this one is support's.
        self::assertSame('unmatched', $this->decode($this->forward(['id' => 'sms-13', 'amount' => self::rial($payment), 'is_deposit' => true]))['status']);
    }

    public function testASumTwoPaymentsAskApprovesNeither(): void
    {
        // Two made before the shop listened: both at the price itself.
        $this->service(DepositSettings::class)->enable(false);
        $first = $this->openCardPayment($this->ali);
        $second = $this->openCardPayment($this->customer(['telegram_id' => 9200]));
        $this->service(DepositSettings::class)->enable(true);

        $response = $this->forward(['id' => 'sms-4', 'amount' => 1500000, 'is_deposit' => true]);

        self::assertSame('ambiguous', $this->decode($response)['status']);
        self::assertSame([PaymentStatus::Pending, PaymentStatus::Pending], [$first->refresh()->status, $second->refresh()->status]);
        self::assertStringContainsString("پرداخت‌های \u{200E}#{$first->id}، \u{200E}#{$second->id} همین مبلغ را می‌خواهند", (string) ReportMessage::query()->where('topic', Topic::Receipts->value)->latest('id')->value('text'));
    }

    public function testATestAWithdrawalAnUnreadSumAndAnOldSmsAreKeptAndNothingMore(): void
    {
        $payment = $this->openCardPayment($this->ali);

        self::assertSame('test', $this->decode($this->forward(['id' => 'test-1', 'amount' => 1000123, 'test' => true]))['status']);
        self::assertSame('ignored', $this->decode($this->forward(['id' => 'sms-5', 'amount' => self::rial($payment), 'is_deposit' => false]))['status'], 'a withdrawal');
        self::assertSame('ignored', $this->decode($this->forward(['id' => 'sms-6', 'amount' => null, 'is_deposit' => true]))['status'], 'no sum');
        self::assertSame('ignored', $this->decode($this->forward(['id' => 'sms-7', 'amount' => self::rial($payment), 'is_deposit' => true, 'received_at' => '2026-11-10T10:00:00+03:30']))['status'], 'four days old');
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertSame(0, ReportMessage::query()->where('topic', Topic::Receipts->value)->count(), 'none of them is support\'s');
    }

    public function testAnSmsFromBeforeThePaymentWasMadeIsNotItsMoney(): void
    {
        $payment = $this->openCardPayment($this->ali);

        $response = $this->forward(['id' => 'sms-8', 'amount' => self::rial($payment), 'is_deposit' => true, 'received_at' => '2026-11-14T09:00:00+00:00']);

        self::assertSame('unmatched', $this->decode($response)['status']);
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertStringContainsString('واریز بدون پرداخت', (string) ReportMessage::query()->where('topic', Topic::Receipts->value)->latest('id')->value('text'), 'support\'s, as any sum no payment asks');
    }

    public function testOnlyTheShopsKeyWhileItListensAndOnlyWhatTheAppSends(): void
    {
        $body = ['id' => 'sms-9', 'amount' => 1500000, 'is_deposit' => true];
        self::assertSame(401, $this->forward($body, key: 'wrong')->getStatusCode());
        self::assertSame(404, $this->forward($body, source: 'nope')->getStatusCode());
        self::assertSame(401, $this->forward($body, bot: 999)->getStatusCode(), 'a shop that is none answers as a wrong key does');
        self::assertSame(422, $this->forward(['amount' => 1500000])->getStatusCode(), 'no id');

        $old = $this->key;
        $this->key = $this->service(DepositSettings::class)->rotate();
        self::assertSame(401, $this->forward($body, key: $old)->getStatusCode(), 'a key made anew: the old one no longer opens it');
        self::assertSame(200, $this->forward($body)->getStatusCode());

        $this->service(DepositSettings::class)->enable(false);
        self::assertSame(401, $this->forward(['id' => 'sms-10', 'amount' => 1500000], key: 'wrong')->getStatusCode(), 'off is told only to the right key');
        self::assertSame(403, $this->forward(['id' => 'sms-10', 'amount' => 1500000])->getStatusCode());
        self::assertSame(0, Deposit::query()->where('external_id', 'sms-10')->count());
    }

    public function testAKeyThatNoLongerDecryptsIsMadeAnewAndSaidSoInTheLog(): void
    {
        $logs = $this->logs();
        // Kept under another APP_KEY: it no longer decrypts.
        $this->service(Settings::class)->set('deposits.key', 'kept-under-another-app-key');

        $key = $this->service(DepositSettings::class)->key();

        self::assertNotSame($this->key, $key);
        self::assertSame(64, strlen($key));
        self::assertTrue($logs->hasWarningThatContains('The deposits key of shop ' . Bot::MAIN . ' cannot be decrypted'), 'support is told the app must be given the new one');
        $said = static fn(): array => array_values(array_filter($logs->getRecords(), static fn(LogRecord $record): bool => str_contains($record->message, 'deposits key')));
        self::assertStringNotContainsString('kept-under-another-app-key', $said()[0]->formatted, 'never what was kept');
        self::assertSame($key, $this->service(DepositSettings::class)->key(), 'kept: the same one asked again');
        self::assertCount(1, $said(), 'logged once');
        $this->key = $key;
        self::assertSame(200, $this->forward(['id' => 'sms-12', 'amount' => 1500000, 'is_deposit' => true])->getStatusCode(), 'the new key opens the webhook');
    }

    public function testThePanelShowsWhereToSendThemAndWhatCame(): void
    {
        $this->loginAsAdmin();
        $this->forward(['id' => 'test-2', 'amount' => 1000123, 'test' => true]);
        $this->forward(['id' => 'sms-11', 'amount' => 1500000, 'is_deposit' => true]);

        $settings = $this->decode($this->get('/api/admin/deposits/settings'));
        self::assertTrue($settings['enabled']);
        self::assertSame($this->key, $settings['key']);
        self::assertSame([['key' => 'forapp', 'label' => 'فوراپ (ForApp)', 'webhook_url' => 'http://localhost/webhooks/deposits/forapp/' . Bot::MAIN]], $settings['sources']);
        self::assertSame([1, '2026-11-14T10:00:00+00:00'], [$settings['waiting'], $settings['last_test_at']]);

        $list = $this->decode($this->get('/api/admin/deposits?status=unmatched'))['deposits'];
        self::assertSame([['sms-11', '150000.00']], array_map(static fn(array $row): array => [Deposit::query()->findOrFail($row['id'])->external_id, $row['amount']], $list));
        self::assertSame(1, count($this->decode($this->get('/api/admin/deposits?search=150000'))['deposits']), 'by its sum in Toman');

        $off = $this->decode($this->putJson('/api/admin/deposits/settings', ['enabled' => false]));
        self::assertSame([false, null], [$off['enabled'], $off['key']]);
        $rotated = $this->decode($this->putJson('/api/admin/deposits/settings', ['enabled' => true]));
        self::assertSame($this->key, $rotated['key'], 'switched on again, the same key');
        self::assertNotSame($this->key, $this->decode($this->postJson('/api/admin/deposits/settings/key'))['key']);
    }

    public function testARefusedPaymentsSumStaysItsOwnAndItsTransferPaysIt(): void
    {
        // Two sums to draw from: 150,001 and 150,002 Toman.
        $this->card->forceFill(['max_amount' => '150002.00'])->save();
        $payments = $this->service(PaymentService::class);
        $refused = $this->openCardPayment($this->ali);
        $payments->submitReceipt($refused, 'file-a', null, null, null);
        self::assertTrue($payments->reject($refused->refresh(), 'admin', 'هنوز نرسیده'));

        $other = $this->openCardPayment($this->customer(['telegram_id' => 9300]));
        self::assertNotSame($refused->amount, $other->amount, 'its order is open: its customer may still transfer the sum shown');

        $response = $this->forward(['id' => 'sms-20', 'amount' => self::rial($refused), 'is_deposit' => true]);
        self::assertSame(['received' => true, 'status' => 'matched', 'payment_id' => $refused->id], $this->decode($response));
        self::assertSame([PaymentStatus::Paid, PaymentStatus::Pending], [$refused->refresh()->status, $other->refresh()->status]);
    }

    public function testASumShownLatelyIsAskedOfNobodyElseForThreeDays(): void
    {
        $this->card->forceFill(['max_amount' => '150002.00'])->save();
        $payments = $this->service(PaymentService::class);

        // Approved by hand before its SMS came: the SMS, when it comes, confirms it — no other payment asks its sum.
        $approved = $this->openCardPayment($this->ali);
        $this->receipt($approved);
        $payments->approve($approved, 'admin', receiptOnly: true);
        $next = $this->openCardPayment($this->customer(['telegram_id' => 9301]));
        self::assertNotSame($approved->amount, $next->amount);
        self::assertSame($approved->id, $this->decode($this->forward(['id' => 'sms-21', 'amount' => self::rial($approved), 'is_deposit' => true]))['payment_id']);
        self::assertSame(PaymentStatus::Pending, $next->refresh()->status);

        // Dropped a moment ago: its customer may transfer the sum yet.
        $payments->cancel($next, 'admin', null);
        $after = $this->openCardPayment($this->customer(['telegram_id' => 9302]));
        self::assertSame($approved->amount, $after->amount, 'the confirmed one\'s sum is free again; the dropped one\'s is not');

        Carbon::setTestNow('2026-11-17 11:00:00');
        self::assertSame($next->amount, $this->openCardPayment($this->customer(['telegram_id' => 9303]))->amount, 'three days on, free again');
    }

    public function testAnSmsForAppDidNotRecogniseAsABanksDepositApprovesNothing(): void
    {
        $payment = $this->openCardPayment($this->ali);

        self::assertSame('unmatched', $this->decode($this->forward(['id' => 'sms-22', 'amount' => self::rial($payment), 'is_deposit' => null, 'bank' => null, 'body' => 'hello']))['status'], 'deposit or withdrawal, ForApp could not tell');
        self::assertSame('unmatched', $this->decode($this->forward(['id' => 'sms-23', 'amount' => self::rial($payment), 'is_deposit' => true, 'bank' => null]))['status'], 'no bank it knows');

        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $reports = ReportMessage::query()->where('topic', Topic::Receipts->value)->pluck('text')->all();
        self::assertCount(2, $reports);
        self::assertStringContainsString('فوراپ این پیامک را واریز یک بانک تشخیص نداد', (string) $reports[1]);
    }

    public function testAnSmsLeftHalfTakenIsDecidedByTheAppsRetry(): void
    {
        $payment = $this->openCardPayment($this->ali);
        $sms = ['id' => 'sms-24', 'amount' => self::rial($payment), 'is_deposit' => true];
        Deposit::query()->create(['source' => 'forapp', 'external_id' => 'sms-24', 'status' => DepositStatus::Received, 'amount_rial' => $sms['amount'], 'received_at' => now()]);

        Carbon::setTestNow('2026-11-14 10:01:00');
        self::assertSame('received', $this->decode($this->forward($sms))['status'], 'its process may be at it still');
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        Carbon::setTestNow('2026-11-14 10:05:00');
        self::assertSame(['received' => true, 'status' => 'matched', 'payment_id' => $payment->id], $this->decode($this->forward($sms)), 'past the time limit its process died: the retry decides it');
        self::assertSame([PaymentStatus::Paid, 1], [$payment->refresh()->status, Deposit::query()->count()]);
    }

    public function testAPaymentApprovedInTheSameMomentIsConfirmedByHandAndSupportsByAnotherSms(): void
    {
        foreach ([['admin', 'matched'], [Deposits::REVIEWER, 'unmatched']] as $n => [$reviewer, $expected]) {
            $payment = $this->openCardPayment($this->customer(['telegram_id' => 9400 + $n]));
            $this->receipt($payment);
            // Approved as the SMS reads it among the payments asking its sum.
            $racing = true;
            $response = $this->decode($this->whileListening('eloquent.retrieved: ' . Payment::class, function (Payment $read) use ($payment, $reviewer, &$racing): void {
                if ($racing && $read->id === $payment->id) {
                    $racing = false;
                    $this->service(PaymentService::class)->approve(Payment::query()->findOrFail($payment->id), $reviewer, receiptOnly: true);
                }
            }, fn(): ResponseInterface => $this->forward(['id' => "sms-race-{$n}", 'amount' => self::rial($payment), 'is_deposit' => true])));

            self::assertSame([$expected, $expected === 'matched' ? $payment->id : null], [$response['status'], $response['payment_id']], $reviewer);
            self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        }
        self::assertSame(1, ReportMessage::query()->where('topic', Topic::Receipts->value)->where('text', 'like', '%واریز بدون پرداخت%')->count(), 'only the second, another SMS\'s payment, is support\'s');
    }

    public function testAPaymentShownNoCardKeepsItsSumWhenShownOne(): void
    {
        $this->botSettings('general', ['support_contact' => '@amo_support']);
        $this->botSettings('payments', ['card_to_support' => true]);
        $order = $this->purchaseOrder($this->ali, $this->plan, $this->server, ['amount' => '150000.00']);
        $first = $this->service(PaymentService::class)->createForOrder($order, $this->card)['payment'];
        self::assertNull($first->card_id);

        $this->botSettings('payments', ['card_to_support' => false]);
        $again = $this->service(PaymentService::class)->createForOrder($order->refresh(), $this->card)['payment'];

        self::assertSame([$first->id, $first->amount], [$again->id, $again->amount], 'its own sum is no other payment\'s');
        self::assertNotNull($again->card_id);
    }

    public function testAPaymentLeftNoRoomForASumOfItsOwnIsNotSaidToApproveItself(): void
    {
        $settings = $this->service(DepositSettings::class);
        self::assertTrue($settings->approvesItself($this->openCardPayment($this->ali)));

        // The method's most is the price: no Toman to add, two payments ask the same sum.
        $this->card->forceFill(['max_amount' => '150000.00'])->save();
        $first = $this->openCardPayment($this->customer(['telegram_id' => 9500]));
        $second = $this->openCardPayment($this->customer(['telegram_id' => 9501]));

        self::assertSame(['150000.00', '150000.00'], [$first->amount, $second->amount]);
        self::assertFalse($settings->approvesItself($first), 'its customer is asked for the receipt as ever');
        self::assertSame('ambiguous', $this->decode($this->forward(['id' => 'sms-25', 'amount' => 1500000, 'is_deposit' => true]))['status']);
    }

    public function testAWholeSumSentAsAFloatIsTakenAndOneInAnotherUnitIsKeptUnread(): void
    {
        $payment = $this->openCardPayment($this->ali);

        $response = $this->forward(['id' => 'sms-26', 'amount' => (float) self::rial($payment), 'is_deposit' => true], flags: JSON_PRESERVE_ZERO_FRACTION);
        self::assertSame(['received' => true, 'status' => 'matched', 'payment_id' => $payment->id], $this->decode($response));

        self::assertSame('ignored', $this->decode($this->forward(['id' => 'sms-27', 'amount' => 150000, 'unit' => 'toman', 'is_deposit' => true]))['status']);
        $kept = Deposit::query()->where('external_id', 'sms-27')->firstOrFail();
        self::assertSame([null, 'مبلغی در پیامک خوانده نشد.'], [$kept->amount_rial, $kept->note], 'kept, its sum unread');
        self::assertSame(422, $this->forward(['id' => 'sms-28', 'amount' => 1500000.5, 'is_deposit' => true])->getStatusCode(), 'no Rial has a fraction');
    }

    public function testTheSmsAreForgottenAfterNinetyDaysOnceTheShopStopsListeningToo(): void
    {
        $this->forward(['id' => 'sms-29', 'amount' => 1500000, 'is_deposit' => true]);
        $this->service(DepositSettings::class)->enable(false);

        Carbon::setTestNow('2027-02-11 10:00:00');
        $this->service(ExpireOrdersTask::class)->run();
        self::assertSame(1, Deposit::query()->count(), '89 days');

        Carbon::setTestNow('2027-02-13 10:00:00');
        $this->service(ExpireOrdersTask::class)->run();
        self::assertSame(0, Deposit::query()->count(), '91 days');
    }

    /** A card payment opened on a purchase of the plan, as the checkout opens one. */
    private function openCardPayment(User $customer): Payment
    {
        $order = $this->purchaseOrder($customer, $this->plan, $this->server, ['amount' => '150000.00']);

        return $this->service(PaymentService::class)->createForOrder($order, $this->card)['payment'];
    }

    /** What the bank's SMS says of the payment's sum: Rial. */
    private static function rial(Payment $payment): int
    {
        return (int) round((float) $payment->amount * 10);
    }

    /**
     * ForApp's request as it posts it by default: JSON, the shop's key in `X-API-Key`.
     *
     * @param array<string, mixed> $body
     */
    private function forward(array $body, ?string $key = null, string $source = 'forapp', int $bot = Bot::MAIN, int $flags = 0): ResponseInterface
    {
        $body += ['test' => false, 'unit' => 'rial', 'sender' => '+98700070', 'bank' => 'ملت', 'body' => 'بانک ملت', 'received_at' => '2026-11-14T10:00:00+00:00'];
        $path = str_replace(['{source:[a-z0-9]+}', '{bot:[0-9]+}'], [$source, (string) $bot], Urls::DEPOSIT_WEBHOOK);
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost' . $path)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-API-Key', $key ?? $this->key)
            ->withBody((new StreamFactory())->createStream((string) json_encode($body, $flags)));

        return $this->app()->http()->handle($request);
    }
}
