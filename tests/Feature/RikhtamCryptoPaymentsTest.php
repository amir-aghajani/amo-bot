<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Drivers\Rikhtam\RikhtamDriver;
use App\Modules\Payments\Drivers\Rikhtam\RikhtamGateway;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\DTO\OnlineRequest;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\OnlineInvoices;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Users\Models\User;
use GuzzleHttp\Client;
use Tests\HttpTestCase;
use Tests\Support\FakeRikhtam;

/**
 * Rikhtam's crypto payments, an online gateway: a checkout makes an invoice in Toman for the payment's order number — in
 * the owner's coin, or the customer's pick on Rikhtam's page —, its key in every call's body, the customer sent back only
 * to an https shop; the same order again is its invoice again. Its state asked of the invoice: paid is paid, new and
 * wait wait while the page takes money (a coin picked late, until the end Rikhtam set for it), expired is closed; an
 * invoice's id written as a number is its all the same; an answer for another order is held; a refusal is logged with
 * what it means for the merchant. The key is kept encrypted, and tells a notification of the gateway's from another's.
 */
final class RikhtamCryptoPaymentsTest extends HttpTestCase
{
    private User $customer;
    private PaymentMethod $method;
    private FakeRikhtam $rikhtam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example', 'telegram.username' => 'amo_shop_bot']);
        $this->telegram();
        $this->rikhtam = $this->rikhtam();
        $this->customer = $this->customer();
        $this->method = $this->rikhtamMethod();
    }

    public function testItIsAnOnlineGatewayOfAKeyAndACoin(): void
    {
        $driver = new RikhtamDriver(new Client());
        $described = $driver->describe()->toArray();
        self::assertSame(['rikhtam', 'ریختم (ارز دیجیتال)'], [$described['key'], $described['label']]);
        self::assertEquals((object) ['kind' => 'online', 'builtin' => false], $described['traits']);
        self::assertSame(['secret' => 'secret', 'coin' => 'choice'], array_column($described['fields'], 'type', 'name'));
        self::assertSame('ارز: انتخاب مشتری در صفحه ریختم', $driver->summary(['secret' => FakeRikhtam::KEY, 'coin' => RikhtamDriver::ANY_COIN]));
        self::assertSame('ارز: تتر روی شبکه ترون (TRC20)', $driver->summary(['secret' => FakeRikhtam::KEY, 'coin' => 'usdt_trc20']));
        self::assertStringNotContainsString(FakeRikhtam::KEY, json_encode($this->method->config, JSON_THROW_ON_ERROR), 'kept encrypted');
    }

    public function testACheckoutMakesAnInvoiceInTomanTheCoinTheCustomersAndShowsItsPage(): void
    {
        $result = $this->topUp('123456');

        self::assertSame(CheckoutOutcome::Link, $result->outcome);
        $id = $this->rikhtam->onlyInvoice();
        self::assertSame("https://rikhtam.com/pay/{$id}", $result->payLink()->url);
        self::assertSame(['123456.00', null, null], [$result->payLink()->amount, $result->payLink()->fee, $result->payLink()->card], 'the order\'s own sum: the coin\'s is Rikhtam\'s page\'s to show');
        self::assertEqualsWithDelta(time() + 60 * 60, $result->payLink()->expiresAt?->getTimestamp(), 5, 'no end given before a coin is chosen: an hour');

        $payment = $result->payment()->refresh();
        $invoices = $this->service(OnlineInvoices::class);
        self::assertSame([
            'a' => 'crypto_create',
            'order_id' => $invoices->orderId($payment),
            'amount' => 123456,
            'currency' => 'IRT',
            'title' => sprintf("سفارش \u{200E}#%d", $payment->order_id),
            'return_url' => $invoices->callbackUrl($payment),
            'secret' => FakeRikhtam::KEY,
        ], $this->rikhtam->invoices[$id]['body'], 'in Toman, no coin: the customer picks it on the page');
        self::assertSame([$id, PaymentStatus::Pending], [$payment->reference, $payment->status]);
        self::assertSame(['invoice_id' => $id, 'order_id' => $invoices->orderId($payment), 'amount' => 123456, 'coin' => null], $payment->invoice);
    }

    public function testTheOwnersCoinIsAskedForAndItsAddressAndEndKept(): void
    {
        $result = $this->topUp('50000', $this->rikhtamMethod('تتر', coin: 'usdt_trc20'));
        $id = $this->rikhtam->onlyInvoice();

        self::assertSame('usdt_trc20', $this->rikhtam->invoices[$id]['body']['coin']);
        self::assertEqualsWithDelta(time() + 1800, $result->payLink()->expiresAt?->getTimestamp(), 5, 'the end Rikhtam gave, in Unix seconds');
        $kept = $result->payment()->refresh()->invoice;
        self::assertSame(['usdt_trc20', 'TXYZ1234567890abcdefghijklmnopqrs', '3.25', substr($id, 4)], [$kept['coin'], $kept['address'], $kept['coin_amount'], $kept['comment']]);
    }

    public function testAShopNotOnHttpsIsNeverReturnedTo(): void
    {
        $this->config(['app.url' => 'http://localhost:8080']);

        self::assertSame(CheckoutOutcome::Link, $this->topUp('50000')->outcome);
        self::assertArrayNotHasKey('return_url', $this->rikhtam->invoices[$this->rikhtam->onlyInvoice()]['body']);
    }

    public function testThePageEndIsUnixSecondsOrTehransMomentWhileItLiesAheadElseAnHour(): void
    {
        self::assertEqualsWithDelta(time() + 600, RikhtamGateway::until(time() + 600)->getTimestamp(), 1);
        self::assertEqualsWithDelta(time() + 600, RikhtamGateway::until((string) (time() + 600))->getTimestamp(), 1);
        $tehran = now()->addMinutes(20)->setTimezone('Asia/Tehran')->format('Y-m-d H:i:s');
        self::assertEqualsWithDelta(time() + 1200, RikhtamGateway::until($tehran)->getTimestamp(), 1, 'a moment with no zone is Tehran\'s');
        self::assertEqualsWithDelta(time() + 1200, RikhtamGateway::until(gmdate('Y-m-d\TH:i:s\Z', time() + 1200))->getTimestamp(), 1, 'one naming its zone is read in it');

        foreach ([null, '', 'soon', time() - 60, time() + 3 * 86400, '1700000000000'] as $misread) {
            self::assertEqualsWithDelta(time() + 3600, RikhtamGateway::until($misread)->getTimestamp(), 5, 'past, too far or unreadable: an hour');
        }
    }

    public function testThePaymentOpenedAgainIsItsInvoiceAgain(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $gateway = $this->service(GatewayRegistry::class)->forPayment($payment);
        self::assertInstanceOf(RikhtamGateway::class, $gateway);

        $invoices = $this->service(OnlineInvoices::class);
        $again = $gateway->open($payment, new OnlineRequest($invoices->orderId($payment), 50000, $invoices->callbackUrl($payment), 'سفارش'));

        self::assertSame($payment->reference, $again->reference);
        self::assertCount(1, $this->rikhtam->invoices);
    }

    public function testItsCallOnlyMakesTheShopAskAndAPaidInvoiceSettles(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->rikhtam->onlyInvoice();
        $callback = (string) parse_url($this->service(OnlineInvoices::class)->callbackUrl($payment), PHP_URL_PATH);

        self::assertSame(['paid' => false], $this->decode($this->send('POST', $callback, [])));
        self::assertSame(OnlineState::Waiting, $this->service(OnlinePayments::class)->check($payment));

        $this->rikhtam->pay($id);
        self::assertSame(['paid' => true], $this->decode($this->send('POST', $callback, [])));
        $payment->refresh();
        self::assertSame([PaymentStatus::Paid, $id, OrderStatus::Fulfilled], [$payment->status, $payment->reference, $payment->order->status]);
        self::assertSame('50000.00', $this->customer->balance());
        self::assertSame('crypto_status', array_column($this->rikhtam->calls, 'a')[1]);
    }

    public function testAnUnpaidInvoiceWaitsWhileItsPageTakesMoneyAndAnExpiredOneCloses(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        self::assertSame(OnlineState::Waiting, $this->service(OnlinePayments::class)->check($payment));

        $payment->forceFill(['link_expires_at' => now()->subMinute()])->save();
        self::assertSame(OnlineState::Closed, $this->service(OnlinePayments::class)->check($payment), 'still new at Rikhtam, its page closed: closed');

        $expired = $this->topUp('40000', $this->rikhtamMethod('ریختم ۲', coin: 'trx'))->payment()->refresh();
        $this->rikhtam->expire((string) $expired->reference);
        self::assertSame(OnlineState::Closed, $this->service(OnlinePayments::class)->check($expired));
        self::assertSame([PaymentStatus::Failed, PaymentService::NOTE_LINK_CLOSED], [$expired->refresh()->status, $expired->note]);
    }

    public function testACoinPickedLateOnItsPageWaitsUntilTheEndRikhtamSetForIt(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->rikhtam->onlyInvoice();
        $payment->forceFill(['link_expires_at' => now()->subMinute()])->save();

        $this->rikhtam->pick($id, 'usdt_ton', time() + 1200);
        self::assertSame(OnlineState::Waiting, $this->service(OnlinePayments::class)->check($payment), 'the shop\'s page closed, but the address Rikhtam gave still takes money');
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $this->rikhtam->pick($id, 'usdt_ton', time() - 60);
        self::assertSame(OnlineState::Closed, $this->service(OnlinePayments::class)->check($payment), 'and once that end passed too: closed');
    }

    public function testAnInvoiceIdRikhtamWritesAsANumberIsKeptAsTextAndAskedAbout(): void
    {
        $this->rikhtam->numbered = true;
        $payment = $this->topUp('50000')->payment()->refresh();

        self::assertSame(['57', '57'], [$payment->reference, $payment->invoice['invoice_id'] ?? null]);
        $this->rikhtam->pay('57');
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment), 'its answer naming it as a number is its own');
        self::assertSame(['crypto_status', '57'], [$this->rikhtam->calls[1]['a'], $this->rikhtam->calls[1]['body']['invoice_id'] ?? null]);
    }

    public function testAnAnswerForAnotherOrderIsHeld(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->rikhtam->onlyInvoice();
        $this->rikhtam->pay($id);
        $this->rikhtam->invoices[$id]['order_id'] = 'amo-999-0123456789';

        try {
            $this->service(OnlinePayments::class)->check($payment);
            self::fail('another order\'s invoice is never this payment');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
            self::assertStringContainsString("Rikhtam said crypto invoice {$id} is order \"amo-999-0123456789\"'s", $e->detail);
        }
        self::assertTrue(OnlinePayments::awaitsSupport($payment->refresh()));
        self::assertSame('0.00', $this->customer->balance());
    }

    public function testAWrongKeyOpensNothingAndIsLoggedWithWhatItMeans(): void
    {
        $logs = $this->logs();

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->rikhtamMethod('ریختم', key: 'rk_wrong_wrong_wrong'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('Rikhtam would not make a crypto invoice (HTTP 403, bad_project): کلید امنیتی درگاه اشتباه است. — the gateway\'s «کلید امنیتی» is wrong'));
        self::assertSame([], $this->rikhtam->invoices);
    }

    public function testOutOfReachChangesNothingAndNeverSaysTheKey(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $this->rikhtam->down();

        try {
            $this->service(OnlinePayments::class)->check($payment);
            self::fail('out of reach');
        } catch (GatewayUnavailableException $e) {
            self::assertFalse($e->held);
            self::assertStringContainsString('Rikhtam is out of reach', $e->detail);
            self::assertStringNotContainsString(FakeRikhtam::KEY, $e->detail);
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testItsKeyTellsItsNotificationsFromAnothers(): void
    {
        $gateway = $this->service(GatewayRegistry::class)->forMethod($this->method);
        self::assertInstanceOf(RikhtamGateway::class, $gateway);

        self::assertTrue($gateway->accepts(FakeRikhtam::KEY));
        self::assertFalse($gateway->accepts('rk_another_gateways_key'));
        self::assertFalse($gateway->accepts(''));
        self::assertFalse((new RikhtamGateway('', '', new Client()))->accepts(''), 'a method without a key accepts none');
    }

    private function topUp(string $amount, ?PaymentMethod $method = null): CheckoutResult
    {
        $orders = $this->service(OrderService::class);

        return $this->service(Checkout::class)->pay($this->customer, $method ?? $this->method, OrderType::WalletTopUp, $amount, fn(?OrderKey $key): Order => $orders->openTopUp($this->customer, (int) $amount, $key));
    }
}
