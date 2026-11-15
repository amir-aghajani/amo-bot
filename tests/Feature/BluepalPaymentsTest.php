<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Drivers\Bluepal\BluepalDriver;
use App\Modules\Payments\Drivers\Bluepal\BluepalGateway;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\OnlineInvoices;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Users\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeBluepal;

/**
 * Bluepal, an online gateway that sends the customer's browser back: a checkout requests the payment in Toman for the
 * method's merchant id and shows Bluepal's page; the browser's return — whatever its query says — only makes the shop
 * verify by the track id it kept: 100 with `success` or 201 (verified before) is paid, the amount Bluepal names held to
 * the order's; 202 waits while its page takes money (Bluepal's 10 minutes and a margin) and closes after — a payment
 * made later still settles it —; any other result is said in the log with what it means, and nothing changes.
 */
final class BluepalPaymentsTest extends HttpTestCase
{
    private User $customer;
    private PaymentMethod $method;
    private FakeBluepal $bluepal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example', 'telegram.username' => 'amo_shop_bot']);
        $this->telegram();
        $this->bluepal = $this->bluepal();
        $this->customer = $this->customer();
        $this->method = $this->bluepalMethod();
    }

    public function testItIsAnOnlineGatewayOfAMerchantIdAndAKey(): void
    {
        $driver = new BluepalDriver(new Client());
        $described = $driver->describe()->toArray();
        self::assertSame(['bluepal', 'بلو پال'], [$described['key'], $described['label']]);
        self::assertEquals((object) ['kind' => 'online', 'builtin' => false], $described['traits']);
        self::assertSame(['merchant_id' => 'text', 'api_key' => 'secret'], array_column($described['fields'], 'type', 'name'));
        self::assertNull($driver->summary(['merchant_id' => FakeBluepal::MERCHANT, 'api_key' => FakeBluepal::KEY]));
        self::assertStringContainsString('چیزی برای کپی کردن', $described['notes'][0], 'the return address is the shop\'s own');

        // Copied with spaces around it, or with Persian digits: kept in Latin; anything else of another shape refused.
        $merchant = array_values(array_filter($driver->describe()->form->fields, static fn(Field $field): bool => $field->name === 'merchant_id'))[0];
        self::assertSame('MRC-58219', $merchant->read(['merchant_id' => ' MRC-۵۸۲۱۹ '], null));
        foreach (['', 'مرچنت من', 'MRC 58219'] as $typed) {
            try {
                $merchant->read(['merchant_id' => $typed], null);
                self::fail("«{$typed}» is no merchant id");
            } catch (FieldRefused $e) {
                self::assertStringContainsString('مرچنت آیدی', $e->getMessage(), $typed);
            }
        }
    }

    public function testACheckoutRequestsThePaymentInTomanAndShowsBluepalsPage(): void
    {
        $result = $this->topUp('50000');

        self::assertSame(CheckoutOutcome::Link, $result->outcome);
        $track = $this->bluepal->onlyPayment();
        self::assertMatchesRegularExpression('/^TRK-\d{10}$/', $track);
        self::assertSame("https://bluepal.ir/pay/{$track}", $result->payLink()->url, 'Bluepal\'s own page');
        self::assertSame(['50000.00', null], [$result->payLink()->amount, $result->payLink()->fee]);
        self::assertEqualsWithDelta(time() + BluepalGateway::PAGE_MINUTES * 60, $result->payLink()->expiresAt?->getTimestamp(), 5);

        $payment = $result->payment()->refresh();
        self::assertSame([
            'merchant_id' => FakeBluepal::MERCHANT,
            'amount' => 50000,
            'callback_url' => $this->service(OnlineInvoices::class)->callbackUrl($payment),
            'description' => sprintf("سفارش \u{200E}#%d", $payment->order_id),
        ], $this->bluepal->payments[$track]['body'], 'in Toman, the customer back at the shop after');
        self::assertSame(['POST', 'Bearer ' . FakeBluepal::KEY], [$this->bluepal->calls[0]['method'], $this->bluepal->calls[0]['authorization']]);
        self::assertSame([$track, ['track_id' => $track, 'amount' => 50000], PaymentStatus::Pending], [$payment->reference, $payment->invoice, $payment->status]);
    }

    public function testTheCustomersReturnOnlyMakesTheShopVerifyAndItIsPaidOnce(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $track = $this->bluepal->onlyPayment();

        // Back before paying, with whatever query: verify says 202 — not yet, its page still open.
        $html = (string) $this->browse($payment, "?track_id={$track}&status=success")->getBody();
        self::assertStringContainsString('هنوز تایید نشده است', $html);
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $this->bluepal->pay($track);
        $html = (string) $this->browse($payment, "?track_id={$track}")->getBody();
        self::assertStringContainsString('پرداخت شما تایید شد', $html);
        $payment->refresh();
        self::assertSame([PaymentStatus::Paid, $track, OrderStatus::Fulfilled], [$payment->status, $payment->reference, $payment->order->status]);
        self::assertSame('50000.00', $this->customer->balance());
        $verify = $this->bluepal->callsTo(BluepalGateway::VERIFY);
        self::assertSame(['GET', '/api/v1/payments/verify/' . $track, 'Bearer ' . FakeBluepal::KEY], [$verify[1]['method'], $verify[1]['path'], $verify[1]['authorization']]);

        // Back again (a refresh): paid already, nothing asked, nothing twice.
        self::assertStringContainsString('پرداخت شما تایید شد', (string) $this->browse($payment, '')->getBody());
        self::assertSame('50000.00', $this->customer->balance());
        self::assertCount(2, $this->bluepal->callsTo(BluepalGateway::VERIFY));
    }

    public function testABankPageThatPostsTheCustomerBackShowsThemThePageNotJson(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $track = $this->bluepal->onlyPayment();
        $this->bluepal->pay($track);

        // A form the bank's page posts in the customer's browser: a page is asked for, so a page is answered.
        $back = $this->send('POST', (string) parse_url($this->service(OnlineInvoices::class)->callbackUrl($payment), PHP_URL_PATH), null, ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8']);

        self::assertStringContainsString('text/html', $back->getHeaderLine('Content-Type'));
        self::assertStringContainsString('پرداخت شما تایید شد', (string) $back->getBody());
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    public function testVerifiedBeforeIsPaidAndAnotherAmountIsHeldForSupport(): void
    {
        // Verified once already — an answer that never came back —: 201, still paid.
        $payment = $this->topUp('50000')->payment()->refresh();
        $track = $this->bluepal->onlyPayment();
        $this->bluepal->pay($track);
        $this->bluepal->payments[$track]['state'] = 'verified';
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame([PaymentStatus::Paid, $track], [$payment->refresh()->status, $payment->reference]);

        $other = $this->topUp('30000', $this->bluepalMethod('بلو پال ۲'))->payment()->refresh();
        $this->bluepal->pay((string) $other->reference, 20000);
        try {
            $this->service(OnlinePayments::class)->check($other);
            self::fail('another amount is never this payment');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
            self::assertStringContainsString("verified track {$other->reference} for 20000 Toman, not payment #{$other->id}'s 30000", $e->detail);
        }
        $other->refresh();
        self::assertSame([PaymentStatus::Pending, PaymentService::NOTE_HELD], [$other->status, $other->note]);
        self::assertTrue(OnlinePayments::awaitsSupport($other));
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testNotPaidWaitsWhileItsPageIsOpenThenCloses(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();

        self::assertSame(OnlineState::Waiting, $this->service(OnlinePayments::class)->check($payment), 'the customer may still be on the bank\'s page');

        // Past Bluepal's own 10 minutes: still waiting, the margin a payment in its last seconds needs.
        Carbon::setTestNow(now()->addMinutes(11));
        self::assertSame(OnlineState::Waiting, $this->service(OnlinePayments::class)->check($payment));

        Carbon::setTestNow(now()->addMinutes(BluepalGateway::PAGE_MINUTES - 10));
        self::assertSame(OnlineState::Closed, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame([PaymentStatus::Failed, PaymentService::NOTE_LINK_CLOSED], [$payment->refresh()->status, $payment->note]);

        // Paid after all, on a page the customer opened late: still settled.
        $this->bluepal->pay((string) $payment->reference);
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testAResultWrittenAsTextAndATrackIdAsANumberAreReadAlike(): void
    {
        $this->bluepal->loose = true;
        $payment = $this->topUp('40000')->payment()->refresh();
        $track = $this->bluepal->onlyPayment();
        self::assertMatchesRegularExpression('/^\d{10}$/', $track);
        self::assertSame([$track, $track], [$payment->reference, $payment->invoice['track_id']]);

        self::assertSame(OnlineState::Waiting, $this->service(OnlinePayments::class)->check($payment), '"202" is 202');
        $this->bluepal->pay($track);
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment), '"100" is 100');
        self::assertSame('40000.00', $this->customer->balance());
    }

    public function testAnAnswerOfNoKnownWordIsAskedAgainLater(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();

        // Result 100 without a status, a status but no result, or a success under an error status: none says paid, nor
        // not paid.
        foreach ([[200, ['result' => 100]], [200, ['status' => 'success']], [500, ['result' => 100, 'status' => 'success']]] as [$status, $answer]) {
            $this->bluepal->answerNext($status, $answer);
            try {
                $this->service(OnlinePayments::class)->check($payment);
                self::fail("HTTP {$status} " . json_encode($answer, JSON_THROW_ON_ERROR) . ' says nothing');
            } catch (GatewayUnavailableException $e) {
                self::assertFalse($e->held);
                self::assertStringContainsString('Bluepal did not say what came of track', $e->detail);
            }
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testARefusalIsLoggedWithWhatItMeansAndChangesNothing(): void
    {
        $logs = $this->logs();

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->bluepalMethod('بلو پال', key: 'sk_live_wrongwrongwrong'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('Bluepal would not open a payment (HTTP 401): Unauthorized — the API key is wrong or revoked'));

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->bluepalMethod('بلو پال ۲', merchant: 'MRC-00000'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('Bluepal would not open a payment (HTTP 403, result 105): merchant is not active — the merchant is not active at Bluepal'));

        $this->bluepal->answerNext(200, ['result' => 100, 'track_id' => 'TRK-1', 'payment_url' => 'https://bluepal.ir.example/pay/TRK-1']);
        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->bluepalMethod('بلو پال ۳'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('Bluepal opened a payment it did not describe (HTTP 200, result 100)'));

        $payment = $this->topUp('50000')->payment()->refresh();
        $refusals = [
            [404, ['result' => 404, 'message' => 'transaction not found'], '(HTTP 404, result 404): transaction not found — Bluepal knows no such transaction'],
            [429, ['result' => 429], '(HTTP 429, result 429) — too many requests just now'],
            [403, ['result' => 105], '(HTTP 403, result 105) — the merchant is not active at Bluepal'],
            [403, [], '(HTTP 403) — Bluepal refused the request: with its allowed domains or IP whitelist set'],
            [500, ['message' => 'Server Error'], '(HTTP 500): Server Error'],
        ];
        foreach ($refusals as [$status, $answer, $said]) {
            $this->bluepal->answerNext($status, $answer);
            try {
                $this->service(OnlinePayments::class)->check($payment);
                self::fail("HTTP {$status} is no verdict");
            } catch (GatewayUnavailableException $e) {
                self::assertFalse($e->held, $said);
                self::assertStringContainsString("Bluepal did not say what came of track {$payment->reference} {$said}", $e->detail);
            }
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testTheKeyIsKeptEncryptedAndNeverShown(): void
    {
        self::assertStringNotContainsString(FakeBluepal::KEY, json_encode($this->method->config, JSON_THROW_ON_ERROR), 'kept encrypted');

        $this->loginAsAdmin();
        $add = fn(array $form) => $this->postJson('/api/admin/payment-methods', $form + ['driver' => 'bluepal', 'label' => 'بلو پال', 'clear_api_key' => false]);
        self::assertSame([
            'merchant_id' => ['مرچنت آیدی بلو پال را وارد کنید.'],
            'api_key' => ['کلید API بلو پال را وارد کنید.'],
        ], $this->decode($add(['merchant_id' => '']))['errors']);

        $response = $add(['merchant_id' => FakeBluepal::MERCHANT, 'api_key' => FakeBluepal::KEY]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->decode($response)['method'];
        self::assertSame(['bluepal', 'online', null], [$row['driver'], $row['kind'], $row['summary']]);
        self::assertSame(['merchant_id' => FakeBluepal::MERCHANT, 'api_key' => ['set' => true, 'hint' => '••••••f605']], $row['config']);
        self::assertStringNotContainsString(FakeBluepal::KEY, json_encode(PaymentMethod::query()->findOrFail($row['id'])->config, JSON_THROW_ON_ERROR), 'kept encrypted');
    }

    public function testOutOfReachChangesNothingAndIsAskedAgainLater(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $this->bluepal->down();

        try {
            $this->service(OnlinePayments::class)->check($payment);
            self::fail('out of reach');
        } catch (GatewayUnavailableException $e) {
            self::assertFalse($e->held);
            self::assertStringContainsString('Bluepal is out of reach', $e->detail);
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertSame(CheckoutOutcome::Refused, $this->topUp('30000', $this->bluepalMethod('بلو پال ۲'))->outcome);
        self::assertCount(1, $this->bluepal->payments);
    }

    private function topUp(string $amount, ?PaymentMethod $method = null): CheckoutResult
    {
        $orders = $this->service(OrderService::class);

        return $this->service(Checkout::class)->pay($this->customer, $method ?? $this->method, OrderType::WalletTopUp, $amount, fn(?OrderKey $key): Order => $orders->openTopUp($this->customer, (int) $amount, $key));
    }

    /** The customer's browser at the payment's callback address, with `$query`. */
    private function browse(Payment $payment, string $query): ResponseInterface
    {
        return $this->send('GET', (string) parse_url($this->service(OnlineInvoices::class)->callbackUrl($payment), PHP_URL_PATH) . $query, null, ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8']);
    }
}
