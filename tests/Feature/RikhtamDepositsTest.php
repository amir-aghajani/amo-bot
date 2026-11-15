<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Urls;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Deposits\Services\Deposits;
use App\Modules\Payments\Deposits\Services\DepositSettings;
use App\Modules\Payments\Enums\DepositStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Deposit;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;
use Tests\Support\FakeRikhtam;

/**
 * Rikhtam's word to the shop's one address: its health test answered to its key alone; a deposit whose sum one open card
 * payment asks approves it (`invoice` its number), anything else a 404 so the account's next gateway may take it — once
 * per deposit, a repeat answered as the first; a deposit attached by hand pays the payment it names when it covers its
 * order; and a crypto invoice paid makes the shop ask Rikhtam — named by its invoice or its order number, and only of a
 * method whose key the word carries, in the shop it came to.
 */
final class RikhtamDepositsTest extends HttpTestCase
{
    private Server $server;
    private Plan $plan;
    private PaymentMethod $card;
    private User $ali;
    private FakeRikhtam $rikhtam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->withoutQr();
        $this->reportGroup();
        $this->rikhtam = $this->rikhtam();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '150000.00'], $this->server);
        $this->card = $this->cardMethod();
        $this->ali = $this->customer(['username' => 'ali']);
        $settings = $this->service(DepositSettings::class);
        $settings->enable(true);
        $settings->connectRikhtam(FakeRikhtam::KEY, 'https://shop.example/webhooks/rikhtam/1', ['ok' => true, 'project_id' => FakeRikhtam::PROJECT, 'domain' => 'shop.example']);
    }

    public function testTheHealthTestIsAnsweredToTheKeyAloneAndKeepsNothing(): void
    {
        $response = $this->rikhtamSends($this->rikhtam->healthTest());
        self::assertSame([200, ['ok' => true, 'test' => true]], [$response->getStatusCode(), $this->decode($response)]);

        self::assertSame(403, $this->rikhtamSends($this->rikhtam->healthTest('rk_wrong'))->getStatusCode());
        self::assertSame(403, $this->rikhtamSends($this->rikhtam->healthTest(''))->getStatusCode(), 'no key');
        self::assertSame(403, $this->rikhtamSends($this->rikhtam->healthTest(), bot: 999)->getStatusCode(), 'a shop that is none answers as a wrong key does');
        self::assertSame(403, $this->rikhtamSends(['key' => [FakeRikhtam::KEY], 'test' => '1'])->getStatusCode(), 'a key that is not text');
        self::assertSame(['ok' => true, 'test' => true], $this->decode($this->rikhtamSends(['test' => 1, 'amount' => 1] + $this->rikhtam->healthTest(), json: true)), 'sent as JSON, its numbers read as a form\'s text');

        $this->service(DepositSettings::class)->enable(false);
        self::assertSame(['ok' => true, 'test' => true], $this->decode($this->rikhtamSends($this->rikhtam->healthTest())), 'the connection works, whether or not the shop listens');
        self::assertSame(0, Deposit::query()->count());
    }

    public function testADepositOfAPaymentsSumApprovesItAndARepeatIsAnsweredAsTheFirst(): void
    {
        $payment = $this->openCardPayment($this->ali);

        $response = $this->rikhtamSends($this->rikhtam->deposit(self::rial($payment), 'dep-1'), parsed: true);

        self::assertSame([200, ['ok' => true, 'invoice' => (string) $payment->id]], [$response->getStatusCode(), $this->decode($response)], (string) $response->getBody());
        $payment->refresh();
        self::assertSame([PaymentStatus::Paid, Deposits::REVIEWER], [$payment->status, $payment->reviewer]);
        self::assertSame(OrderStatus::Fulfilled, $payment->order->refresh()->status, 'the service delivered');
        self::assertCount(1, FakeProvider::$created);
        $deposit = Deposit::query()->sole();
        self::assertSame(['rikhtam', 'dep-1', DepositStatus::Matched, $payment->id, null, 'ریختم'], [$deposit->source, $deposit->external_id, $deposit->status, $deposit->payment_id, $deposit->bank, $deposit->sender]);
        self::assertStringContainsString("به حساب \u{200E}…6789", (string) $deposit->body, 'a line of what came, in place of the SMS Rikhtam does not send');

        // Rikhtam did not hear the answer and sends it again: the same answer, nothing twice.
        $again = $this->rikhtamSends($this->rikhtam->deposit(self::rial($payment), 'dep-1'));
        self::assertSame([200, ['ok' => true, 'invoice' => (string) $payment->id]], [$again->getStatusCode(), $this->decode($again)]);
        self::assertSame(1, Deposit::query()->count());
        self::assertCount(1, FakeProvider::$created);
    }

    public function testASumNoPaymentAsksIsA404KeptForSupport(): void
    {
        $payment = $this->openCardPayment($this->ali);

        self::assertSame(404, $this->rikhtamSends($this->rikhtam->deposit(1_500_000, 'dep-2'))->getStatusCode());
        self::assertSame(404, $this->rikhtamSends($this->rikhtam->deposit(1_500_000, 'dep-2'))->getStatusCode(), 'its repeat too');

        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertSame([DepositStatus::Unmatched], Deposit::query()->pluck('status')->all());
        self::assertStringContainsString('واریز بدون پرداخت', (string) ReportMessage::query()->where('topic', Topic::Receipts->value)->latest('id')->value('text'));
    }

    public function testASumTwoPaymentsAskApprovesNeitherAndIsA404(): void
    {
        $settings = $this->service(DepositSettings::class);
        $settings->enable(false);
        $first = $this->openCardPayment($this->ali);
        $second = $this->openCardPayment($this->customer(['telegram_id' => 9200]));
        $settings->enable(true);

        self::assertSame(404, $this->rikhtamSends($this->rikhtam->deposit(1_500_000, 'dep-3'))->getStatusCode());

        self::assertSame([PaymentStatus::Pending, PaymentStatus::Pending], [$first->refresh()->status, $second->refresh()->status]);
        self::assertSame(DepositStatus::Ambiguous, Deposit::query()->sole()->status);
        self::assertStringContainsString("پرداخت‌های \u{200E}#{$first->id}، \u{200E}#{$second->id} همین مبلغ را می‌خواهند", (string) ReportMessage::query()->where('topic', Topic::Receipts->value)->latest('id')->value('text'));
    }

    public function testWhileTheShopDoesNotListenADepositIsNotTaken(): void
    {
        $payment = $this->openCardPayment($this->ali);
        $this->service(DepositSettings::class)->enable(false);

        self::assertSame(404, $this->rikhtamSends($this->rikhtam->deposit(self::rial($payment), 'dep-4'))->getStatusCode());
        self::assertSame([0, PaymentStatus::Pending], [Deposit::query()->count(), $payment->refresh()->status]);
    }

    public function testAKeyOnlyACryptoMethodHasOpensNoDeposit(): void
    {
        $this->service(DepositSettings::class)->connectRikhtam('rk_deposits_own', 'https://shop.example/webhooks/rikhtam/1', ['ok' => true]);
        $this->rikhtamMethod();
        $payment = $this->openCardPayment($this->ali);

        self::assertSame(['ok' => true, 'test' => true], $this->decode($this->rikhtamSends($this->rikhtam->healthTest())), 'the crypto method\'s key is the shop\'s too');
        self::assertSame(404, $this->rikhtamSends($this->rikhtam->deposit(self::rial($payment), 'dep-5'))->getStatusCode());
        self::assertSame(0, Deposit::query()->count());

        $response = $this->rikhtamSends($this->rikhtam->deposit(self::rial($payment), 'dep-5', key: 'rk_deposits_own'));
        self::assertSame([200, (string) $payment->id], [$response->getStatusCode(), $this->decode($response)['invoice'] ?? null]);

        $this->service(DepositSettings::class)->forgetRikhtam();
        self::assertSame(403, $this->rikhtamSends($this->rikhtam->deposit(self::rial($payment), 'dep-6', key: 'rk_deposits_own'))->getStatusCode(), 'forgotten: refused');
    }

    public function testACryptoInvoicePaidMakesTheShopAskRikhtamAndSettlesItsPayment(): void
    {
        $payment = $this->cryptoPayment($this->rikhtamMethod());
        $invoice = $this->rikhtam->onlyInvoice();
        $this->rikhtam->pay($invoice);

        $response = $this->rikhtamSends($this->rikhtam->cryptoPaid($invoice));

        self::assertSame([200, ['ok' => true]], [$response->getStatusCode(), $this->decode($response)]);
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        self::assertSame('crypto_status', $this->rikhtam->calls[array_key_last($this->rikhtam->calls)]['a'], 'Rikhtam itself asked: the word alone moves nothing');

        $logs = $this->logs();
        self::assertSame(['ok' => true], $this->decode($this->rikhtamSends($this->rikhtam->cryptoPaid('inv_unknown'))));
        self::assertTrue($logs->hasWarningThatContains('no payment of the shop has it'));
        self::assertSame(403, $this->rikhtamSends($this->rikhtam->cryptoPaid($invoice, 'rk_wrong'))->getStatusCode());
        self::assertSame(0, Deposit::query()->count(), 'no deposit');
    }

    public function testACryptoWordNamingOnlyItsOrderIsHeardToo(): void
    {
        $payment = $this->cryptoPayment($this->rikhtamMethod());
        $invoice = $this->rikhtam->onlyInvoice();
        $this->rikhtam->pay($invoice);
        $word = $this->rikhtam->cryptoPaid($invoice);
        unset($word['invoice_id']);

        self::assertSame(['ok' => true], $this->decode($this->rikhtamSends($word)));
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status, 'by its order number, the field Rikhtam\'s guide says to go by');
    }

    public function testACryptoWordActsOnlyOnAPaymentOfAMethodWhoseKeyItCarriesInItsOwnShop(): void
    {
        $payment = $this->cryptoPayment($this->rikhtamMethod());
        $invoice = $this->rikhtam->onlyInvoice();
        $this->rikhtam->pay($invoice);
        $this->rikhtamMethod('ریختم دیگر', key: 'rk_another_gateway_0001');
        $agentShop = $this->agentBot(traffic: 0);
        CurrentBot::run($agentShop, fn(): PaymentMethod => $this->rikhtamMethod('ریختم نماینده', key: 'rk_agent_gateway_0001'));
        $asked = count($this->rikhtam->calls);

        self::assertSame(['ok' => true], $this->decode($this->rikhtamSends($this->rikhtam->cryptoPaid($invoice, 'rk_another_gateway_0001'))), 'another method\'s key: heard, nothing done');
        self::assertSame(['ok' => true], $this->decode($this->rikhtamSends($this->rikhtam->cryptoPaid($invoice, 'rk_agent_gateway_0001'), bot: $agentShop->id)), 'another shop\'s key, at its own address');
        self::assertSame(403, $this->rikhtamSends($this->rikhtam->cryptoPaid($invoice), bot: $agentShop->id)->getStatusCode(), 'this shop\'s key at another shop\'s address');

        self::assertCount($asked, $this->rikhtam->calls, 'Rikhtam never asked');
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testADepositAttachedByHandPaysThePaymentItNamesOnceItCoversItsOrder(): void
    {
        $payment = $this->openCardPayment($this->ali);
        self::assertNotSame('150000.00', $payment->amount, 'a sum of its own');
        // The customer rounded the sum down to the price itself: no payment asks it, and the account's other gateways took
        // none of it either.
        self::assertSame(404, $this->rikhtamSends($this->rikhtam->deposit(1_500_000, 'dep-7'))->getStatusCode());

        $attached = $this->rikhtamSends($this->rikhtam->deposit(1_500_000, 'dep-7', ['invoice' => (string) $payment->id, 'manual' => '1']));

        self::assertSame([200, ['ok' => true, 'invoice' => (string) $payment->id]], [$attached->getStatusCode(), $this->decode($attached)], (string) $attached->getBody());
        self::assertSame([PaymentStatus::Paid, Deposits::REVIEWER], [$payment->refresh()->status, $payment->reviewer]);
        $deposit = Deposit::query()->sole();
        self::assertSame([DepositStatus::Matched, $payment->id, 'دستی به این پرداخت پیوست شد و آن را تایید کرد.'], [$deposit->status, $deposit->payment_id, $deposit->note]);

        self::assertSame(200, $this->rikhtamSends($this->rikhtam->deposit(1_500_000, 'dep-7', ['invoice' => (string) $payment->id, 'manual' => '1']))->getStatusCode(), 'sent again: confirmed, as the first time');
        $other = $this->openCardPayment($this->customer(['telegram_id' => 9300]));
        self::assertSame(409, $this->rikhtamSends($this->rikhtam->deposit(1_500_000, 'dep-7', ['invoice' => (string) $other->id, 'manual' => '1']))->getStatusCode(), 'one deposit pays one payment');
        self::assertSame(PaymentStatus::Pending, $other->refresh()->status);
        self::assertCount(1, FakeProvider::$created);
    }

    public function testAnAttachNamesItsPaymentByNumberAndIsRefusedAsRikhtamReadsIt(): void
    {
        $attach = fn(string $uid, int|string $invoice, int $rial = 1_600_000): ResponseInterface => $this->rikhtamSends($this->rikhtam->deposit($rial, $uid, ['invoice' => (string) $invoice, 'manual' => '1']));

        self::assertSame(404, $attach('dep-10', 999999)->getStatusCode(), 'no payment of that number');
        self::assertSame(404, $attach('dep-11', 'سفارش')->getStatusCode(), 'no number at all');
        $online = $this->cryptoPayment($this->rikhtamMethod());
        self::assertSame(404, $attach('dep-12', $online->id)->getStatusCode(), 'not a card payment');

        // Approved by support before: the deposit confirms it, as one found by its sum would — nothing delivered twice.
        $approved = $this->openCardPayment($this->ali);
        $this->service(PaymentService::class)->approve($approved, 'admin');
        $confirms = $attach('dep-13', $approved->id);
        self::assertSame([200, ['ok' => true, 'invoice' => (string) $approved->id]], [$confirms->getStatusCode(), $this->decode($confirms)]);
        $confirming = Deposit::query()->where('external_id', 'dep-13')->sole();
        self::assertSame([DepositStatus::Matched, $approved->id, 'admin'], [$confirming->status, $confirming->payment_id, $approved->refresh()->reviewer]);
        self::assertCount(1, FakeProvider::$created);
        self::assertSame(409, $attach('dep-17', $approved->id)->getStatusCode(), 'named by a deposit already: never twice');

        $cancelled = $this->openCardPayment($this->customer(['telegram_id' => 9400]));
        $this->service(PaymentService::class)->cancel($cancelled, 'admin', null);
        self::assertSame(410, $attach('dep-14', $cancelled->id)->getStatusCode());

        $short = $this->openCardPayment($this->customer(['telegram_id' => 9401]));
        self::assertSame(409, $attach('dep-15', $short->id, 1_499_990)->getStatusCode(), 'ten Rial short of the order\'s price');
        $kept = Deposit::query()->where('external_id', 'dep-15')->sole();
        self::assertSame([DepositStatus::Unmatched, null, PaymentStatus::Pending], [$kept->status, $kept->payment_id, $short->refresh()->status]);
        self::assertStringContainsString("کمتر از مبلغ سفارش پرداخت \u{200E}#{$short->id}", (string) $kept->note);

        self::assertSame(409, $attach('dep-15', $short->id, 1_500_000)->getStatusCode(), 'a deposit\'s sum is what it said the first time');

        // While the shop does not listen, its owner's own attach is taken all the same.
        $this->service(DepositSettings::class)->enable(false);
        self::assertSame(200, $attach('dep-16', $short->id, 1_500_000)->getStatusCode());
        self::assertSame([PaymentStatus::Paid, $short->id], [$short->refresh()->status, Deposit::query()->where('external_id', 'dep-16')->value('payment_id')]);
        self::assertSame(['dep-10', 'dep-11', 'dep-12', 'dep-17', 'dep-14', 'dep-15'], Deposit::query()->where('status', DepositStatus::Unmatched->value)->orderBy('id')->pluck('external_id')->all(), 'each kept, its note saying why');
    }

    /** A wallet top-up of 50,000 Toman paid with the Rikhtam crypto method `$method`, its invoice made. */
    private function cryptoPayment(PaymentMethod $method): Payment
    {
        $orders = $this->service(OrderService::class);

        return $this->service(Checkout::class)
            ->pay($this->ali, $method, OrderType::WalletTopUp, '50000', fn(?OrderKey $key): Order => $orders->openTopUp($this->ali, 50000, $key))
            ->payment();
    }

    public function testADepositOfAPaymentSupportApprovedConfirmsItAndOneTooOldOrBeingDecidedPaysNothing(): void
    {
        $approved = $this->openCardPayment($this->ali);
        $this->service(PaymentService::class)->approve($approved, 'admin');
        self::assertCount(1, FakeProvider::$created);

        $confirmed = $this->rikhtamSends($this->rikhtam->deposit(self::rial($approved), 'dep-50'));

        self::assertSame([200, ['ok' => true, 'invoice' => (string) $approved->id]], [$confirmed->getStatusCode(), $this->decode($confirmed)], 'the money for it came: Rikhtam confirms the deposit, nothing approved twice');
        $deposit = Deposit::query()->sole();
        self::assertSame([DepositStatus::Matched, $approved->id, 'admin'], [$deposit->status, $deposit->payment_id, $approved->refresh()->reviewer]);
        self::assertStringContainsString('پیش‌تر تایید شده بود', (string) $deposit->note);
        self::assertCount(1, FakeProvider::$created);

        $open = $this->openCardPayment($this->customer(['telegram_id' => 9500]));
        $old = $this->rikhtamSends($this->rikhtam->deposit(self::rial($open), 'dep-51', ['sms_time' => (string) (time() - 73 * 3600)]));
        self::assertSame([404, DepositStatus::Ignored, PaymentStatus::Pending], [$old->getStatusCode(), Deposit::query()->where('external_id', 'dep-51')->value('status'), $open->refresh()->status], 'older than three days');

        // The same deposit, sent again while another request still decides it: Rikhtam is asked to send it later.
        Deposit::query()->create(['source' => 'rikhtam', 'external_id' => 'dep-52', 'status' => DepositStatus::Received, 'amount_rial' => self::rial($open), 'received_at' => now()]);
        $busy = $this->rikhtamSends($this->rikhtam->deposit(self::rial($open), 'dep-52'));
        self::assertSame([503, PaymentStatus::Pending], [$busy->getStatusCode(), $open->refresh()->status]);
    }

    public function testOneTransferBothSourcesTellApprovesOnceAndNeverAPaymentThatAskedItsSumSince(): void
    {
        $payment = $this->openCardPayment($this->ali);
        $rial = self::rial($payment);
        $forApp = $this->forApp(['id' => 'sms-1', 'amount' => $rial]);
        self::assertSame(['matched', $payment->id], [$forApp['status'], $forApp['payment_id']], 'ForApp told it first');
        // Its deposit came: its sum is free again, and another customer's payment asks it now.
        $since = $this->openCardPayment($this->customer(['telegram_id' => 9600]));
        $since->forceFill(['amount' => $payment->amount])->save();

        $rikhtam = $this->rikhtamSends($this->rikhtam->deposit($rial, 'dep-80'));

        self::assertSame([200, ['ok' => true, 'invoice' => (string) $payment->id]], [$rikhtam->getStatusCode(), $this->decode($rikhtam)], 'Rikhtam\'s word on the same transfer confirms the payment it paid');
        self::assertSame(PaymentStatus::Pending, $since->refresh()->status, 'never the payment that asked its sum since');
        $told = Deposit::query()->where('external_id', 'dep-80')->sole();
        self::assertSame([DepositStatus::Matched, $payment->id], [$told->status, $told->payment_id]);
        self::assertStringContainsString('منبع دیگری', (string) $told->note);
        self::assertCount(1, FakeProvider::$created, 'delivered once');
        self::assertSame(0, ReportMessage::query()->where('text', 'like', '%واریز بدون پرداخت%')->count(), 'nothing for support');

        // The second customer's own transfer, of the same sum: ForApp's word on it pays their payment.
        self::assertSame(['matched', $since->id], [($second = $this->forApp(['id' => 'sms-2', 'amount' => $rial]))['status'], $second['payment_id']]);
        self::assertSame(PaymentStatus::Paid, $since->refresh()->status);
        self::assertCount(2, FakeProvider::$created);

        // Rikhtam first this time: ForApp's word confirms.
        $other = $this->openCardPayment($this->customer(['telegram_id' => 9601]));
        self::assertSame(200, $this->rikhtamSends($this->rikhtam->deposit(self::rial($other), 'dep-81'))->getStatusCode());
        self::assertSame(['matched', $other->id], [($late = $this->forApp(['id' => 'sms-3', 'amount' => self::rial($other)]))['status'], $late['payment_id']]);
        self::assertCount(3, FakeProvider::$created);
        self::assertSame(0, ReportMessage::query()->where('text', 'like', '%واریز بدون پرداخت%')->count());
    }

    public function testOneTransferBothSourcesTellAtOnceApprovesOnceAndBothSayIt(): void
    {
        $payment = $this->openCardPayment($this->ali);
        $racing = true;
        // ForApp's word on the same transfer approves the payment as Rikhtam's reads it among those asking its sum.
        $response = $this->whileListening('eloquent.retrieved: ' . Payment::class, function (Payment $read) use ($payment, &$racing): void {
            if ($racing && $read->id === $payment->id) {
                $racing = false;
                Deposit::query()->create(['source' => 'forapp', 'external_id' => 'sms-race', 'status' => DepositStatus::Received, 'amount_rial' => self::rial($payment), 'received_at' => now()]);
                $this->service(PaymentService::class)->approve(Payment::query()->findOrFail($payment->id), Deposits::REVIEWER);
            }
        }, fn(): ResponseInterface => $this->rikhtamSends($this->rikhtam->deposit(self::rial($payment), 'dep-82')));

        self::assertSame([200, ['ok' => true, 'invoice' => (string) $payment->id]], [$response->getStatusCode(), $this->decode($response)], (string) $response->getBody());
        self::assertSame([DepositStatus::Matched, $payment->id], [Deposit::query()->where('external_id', 'dep-82')->value('status'), Deposit::query()->where('external_id', 'dep-82')->value('payment_id')]);
        self::assertCount(1, FakeProvider::$created);
        self::assertSame(0, ReportMessage::query()->where('text', 'like', '%واریز بدون پرداخت%')->count());
    }

    public function testRikhtamIsNoAppTheSharedDepositWebhookTakes(): void
    {
        $payment = $this->openCardPayment($this->ali);
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://shop.example' . str_replace(['{source:[a-z0-9]+}', '{bot:[0-9]+}'], ['rikhtam', (string) Bot::MAIN], Urls::DEPOSIT_WEBHOOK))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-API-Key', $this->service(DepositSettings::class)->key())
            ->withBody((new StreamFactory())->createStream((string) json_encode(['uid' => 'dep-60', 'amount' => (string) self::rial($payment)])));

        self::assertSame(404, $this->app()->http()->handle($request)->getStatusCode(), 'the phone app\'s key opens no Rikhtam deposit');
        self::assertSame([0, PaymentStatus::Pending], [Deposit::query()->count(), $payment->refresh()->status]);
    }

    public function testAnInvoiceAloneMakesAnAttachByHandAsRikhtamsOwnCodeReadsIt(): void
    {
        $payment = $this->openCardPayment($this->ali);
        $this->service(DepositSettings::class)->enable(false);

        // An empty invoice is a deposit sent as it came: not taken while the shop does not listen, `manual` or not.
        self::assertSame(404, $this->rikhtamSends($this->rikhtam->deposit(1_500_000, 'dep-70', ['invoice' => ' ', 'manual' => '1']))->getStatusCode());
        self::assertSame(0, Deposit::query()->count());

        $attached = $this->rikhtamSends($this->rikhtam->deposit(1_500_000, 'dep-71', ['invoice' => '#' . $payment->id]));

        self::assertSame([200, ['ok' => true, 'invoice' => (string) $payment->id]], [$attached->getStatusCode(), $this->decode($attached)], 'no `manual=1` beside it');
        self::assertSame([PaymentStatus::Paid, DepositStatus::Matched], [$payment->refresh()->status, Deposit::query()->sole()->status]);
    }

    /** A card payment opened on a purchase of the plan, as the checkout opens one. */
    private function openCardPayment(User $customer): Payment
    {
        $order = $this->purchaseOrder($customer, $this->plan, $this->server, ['amount' => '150000.00']);

        return $this->service(PaymentService::class)->createForOrder($order, $this->card)['payment'];
    }

    /**
     * ForApp's forward of a bank's deposit SMS to the shop (its own webhook, the shop's key), received now: what the shop
     * answered.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function forApp(array $body): array
    {
        $body += ['test' => false, 'unit' => 'rial', 'is_deposit' => true, 'sender' => '+98700070', 'bank' => 'ملت', 'body' => 'بانک ملت', 'received_at_ms' => time() * 1000];
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://shop.example' . str_replace(['{source:[a-z0-9]+}', '{bot:[0-9]+}'], ['forapp', (string) Bot::MAIN], Urls::DEPOSIT_WEBHOOK))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-API-Key', $this->service(DepositSettings::class)->key())
            ->withBody((new StreamFactory())->createStream((string) json_encode($body)));

        return $this->decode($this->app()->http()->handle($request));
    }

    /** What the bank's SMS says of the payment's sum: Rial. */
    private static function rial(Payment $payment): int
    {
        return (int) round((float) $payment->amount * 10);
    }

    /**
     * Rikhtam's request to the shop's address: a form (`$parsed`: as PHP hands it over, else only as the body) — or, with
     * `$json`, the same fields as JSON.
     *
     * @param array<string, mixed> $form
     */
    private function rikhtamSends(array $form, int $bot = Bot::MAIN, bool $parsed = false, bool $json = false): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://shop.example' . str_replace('{bot:[0-9]+}', (string) $bot, Urls::RIKHTAM_WEBHOOK))
            ->withHeader('Content-Type', $json ? 'application/json' : 'application/x-www-form-urlencoded')
            ->withHeader('User-Agent', 'Rikhtam/1.0')
            ->withBody((new StreamFactory())->createStream($json ? json_encode($form, JSON_THROW_ON_ERROR) : http_build_query($form)));

        return $this->app()->http()->handle($parsed ? $request->withParsedBody($form) : $request);
    }
}
