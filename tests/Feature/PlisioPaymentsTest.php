<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Drivers\Plisio\PlisioDriver;
use App\Modules\Payments\Drivers\Plisio\PlisioGateway;
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
use Tests\HttpTestCase;
use Tests\Support\FakePlisio;

/**
 * Plisio, an online gateway for crypto: a checkout opens an invoice in US dollars — the order's Toman at the owner's rate,
 * a cent rounded up — calling the shop back and sending the customer back to it, its key in every call's query and never
 * in a message; its state asked of the invoice: completed or overpaid is paid, money seen on the chain is money in, an
 * expired or erring one with money on it is held, a coin switched makes the paid invoice another, found by the order's
 * number; a white-label shop's answer for another order or fewer dollars is held.
 */
final class PlisioPaymentsTest extends HttpTestCase
{
    private User $customer;
    private PaymentMethod $method;
    private FakePlisio $plisio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example', 'telegram.username' => 'amo_shop_bot']);
        $this->telegram();
        $this->plisio = $this->plisio();
        $this->customer = $this->customer();
        $this->method = $this->plisioMethod(currencies: 'USDT_TRX,TRX');
    }

    public function testItIsAnOnlineGatewayOfAKeyARateAndCoins(): void
    {
        $driver = new PlisioDriver(new Client());
        $described = $driver->describe()->toArray();
        self::assertSame(['plisio', 'Plisio'], [$described['key'], $described['label']]);
        self::assertEquals((object) ['kind' => 'online', 'builtin' => false], $described['traits']);
        self::assertSame(['api_key' => 'secret', 'usd_rate' => 'number', 'currencies' => 'text'], array_column($described['fields'], 'type', 'name'));
        self::assertSame('هر دلار ۱۰۰٬۰۰۰ تومان · USDT_TRX، TRX', $driver->summary(['api_key' => FakePlisio::KEY, 'usd_rate' => 100_000, 'currencies' => 'USDT_TRX,TRX']));
    }

    public function testTomanAreDollarsAtTheRateACentRoundedUp(): void
    {
        self::assertSame('0.50', PlisioGateway::dollars(50_000, 100_000));
        self::assertSame('1.24', PlisioGateway::dollars(123_456, 100_000));
        self::assertSame('1.00', PlisioGateway::dollars(100_000, 100_000));
        self::assertSame('0.01', PlisioGateway::dollars(1, 100_000), 'never nothing');
    }

    public function testACheckoutOpensAnInvoiceInDollarsAndShowsItsPage(): void
    {
        $result = $this->topUp('123456');

        self::assertSame(CheckoutOutcome::Link, $result->outcome);
        $id = $this->plisio->onlyInvoice();
        self::assertSame("https://plisio.net/invoice/{$id}", $result->payLink()->url);
        self::assertSame(['123456.00', null, null], [$result->payLink()->amount, $result->payLink()->fee, $result->payLink()->card], 'the order\'s own sum: the coin\'s is Plisio\'s to show');
        self::assertEqualsWithDelta(time() + 60 * 60, $result->payLink()->expiresAt?->getTimestamp(), 5);

        $payment = $result->payment()->refresh();
        $invoices = $this->service(OnlineInvoices::class);
        self::assertSame([
            'order_number' => $invoices->orderId($payment),
            'order_name' => sprintf("سفارش \u{200E}#%d", $payment->order_id),
            'description' => sprintf("سفارش \u{200E}#%d", $payment->order_id),
            'source_currency' => 'USD',
            'source_amount' => '1.24',
            'expire_min' => '60',
            'return_existing' => 'true',
            'allowed_psys_cids' => 'USDT_TRX,TRX',
            'callback_url' => $invoices->callbackUrl($payment),
            'success_invoice_url' => $invoices->callbackUrl($payment),
            'fail_invoice_url' => $invoices->callbackUrl($payment),
            'api_key' => FakePlisio::KEY,
        ], $this->plisio->invoices[$id]['query'], 'in dollars at the owner\'s rate, a cent rounded up');
        self::assertSame([$id, PaymentStatus::Pending, '1.24', 100_000], [$payment->reference, $payment->status, $payment->invoice['usd'], $payment->invoice['rate']]);
    }

    public function testItsCallOnlyMakesTheShopAskAndAPaidInvoiceSettlesAtTheOrdersPrice(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->plisio->onlyInvoice();
        $callback = $this->callbackPath($payment);

        self::assertSame(['paid' => false], $this->decode($this->send('POST', $callback, ['txn_id' => $id, 'status' => 'completed', 'verify_hash' => 'not-trusted'])));

        // Seen on the chain, awaiting its confirmations: money in, waited for.
        $this->plisio->seen($id);
        self::assertSame(OnlineState::Waiting, $this->service(OnlinePayments::class)->check($payment));
        self::assertTrue(OnlinePayments::hasMoneyIn($payment->refresh()));

        $this->plisio->pay($id);
        self::assertSame(['paid' => true], $this->decode($this->send('POST', $callback, ['txn_id' => $id])));
        $payment->refresh();
        self::assertSame([PaymentStatus::Paid, $id, OrderStatus::Fulfilled], [$payment->status, $payment->reference, $payment->order->status]);
        self::assertSame('50000.00', $this->customer->balance());

        // The customer's browser back from Plisio's page: told it was paid, with the way back to the bot.
        $page = (string) $this->send('GET', $callback, null, ['Accept' => 'text/html'])->getBody();
        self::assertStringContainsString('پرداخت شما تایید شد', $page);
        self::assertStringContainsString('href="https://t.me/amo_shop_bot"', $page);
    }

    public function testAnOverpaidInvoiceIsPaid(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $this->plisio->overpay($this->plisio->onlyInvoice());

        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testACoinSwitchedPaysAnotherInvoiceOfTheOrderFoundByItsNumber(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $first = $this->plisio->onlyInvoice();
        $other = $this->plisio->switchCoin($first);

        $this->plisio->pay($other);

        self::assertSame('cancelled duplicate', $this->plisio->invoices[$first]['status']);
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame([PaymentStatus::Paid, $other], [$payment->refresh()->status, $payment->reference], 'the paid invoice is the reference');
        self::assertSame($this->service(OnlineInvoices::class)->orderId($payment), $this->plisio->callsTo('/operations')[0]['query']['search']);
    }

    public function testAnExpiredInvoiceClosesUnlessMoneyCameOnItThenItIsHeld(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $this->plisio->expire($this->plisio->onlyInvoice());
        self::assertSame(OnlineState::Closed, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame([PaymentStatus::Failed, PaymentService::NOTE_LINK_CLOSED], [$payment->refresh()->status, $payment->note]);

        $short = $this->topUp('40000', $this->plisioMethod('Plisio 2'))->payment()->refresh();
        $this->plisio->expire((string) $short->reference, withMoney: true);
        try {
            $this->service(OnlinePayments::class)->check($short);
            self::fail('money came on an expired invoice: paid short or late');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
        }
        self::assertTrue(OnlinePayments::awaitsSupport($short->refresh()));

        $cancelled = $this->topUp('30000', $this->plisioMethod('Plisio 3'))->payment()->refresh();
        $this->plisio->cancel((string) $cancelled->reference);
        self::assertSame(OnlineState::Closed, $this->service(OnlinePayments::class)->check($cancelled));
    }

    public function testAWhiteLabelAnswerForAnotherOrderOrFewerDollarsIsHeld(): void
    {
        $this->plisio->whiteLabel = true;
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->plisio->onlyInvoice();
        $this->plisio->pay($id);
        $this->plisio->invoices[$id]['usd'] = '0.40';

        try {
            $this->service(OnlinePayments::class)->check($payment);
            self::fail('fewer dollars than asked is never this payment');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
        }
        self::assertSame([PaymentStatus::Pending, '0.00'], [$payment->refresh()->status, $this->customer->balance()]);

        // The same answer in full and for this order is paid — and its page's end is Plisio's own.
        $fine = $this->topUp('30000', $this->plisioMethod('Plisio 2'));
        self::assertEqualsWithDelta(time() + 60 * 60, $fine->payLink()->expiresAt?->getTimestamp(), 5);
        $this->plisio->pay((string) $fine->payment()->reference);
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($fine->payment()->refresh()));
    }

    public function testAShopNotOnHttpsIsAskedAboutAndNeverCalledOrReturnedTo(): void
    {
        $this->config(['app.url' => 'http://localhost:8080']);

        self::assertSame(CheckoutOutcome::Link, $this->topUp('50000')->outcome);
        $query = $this->plisio->invoices[$this->plisio->onlyInvoice()]['query'];
        self::assertSame([], array_intersect_key($query, ['callback_url' => 1, 'success_invoice_url' => 1, 'fail_invoice_url' => 1]));
    }

    public function testAWrongKeyNoRateOrASumBelowTheCoinsLeastOpensNothingAndTheKeyIsNeverInAMessage(): void
    {
        $logs = $this->logs();

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->plisioMethod('Plisio', key: 'pl_sk_wrong_wrong_wrong'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('Plisio would not open an invoice (HTTP 401, code 101): Please check your secret key is correct and domain is verified — the secret key is wrong'));

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->plisioMethod('Plisio 2', rate: 0))->outcome);
        self::assertTrue($logs->hasWarningThatContains('has no dollar rate'));

        $this->plisio->least = '5.00';
        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->plisioMethod('Plisio 3'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('code 105): Amount is less than minimum — less than the least Plisio takes'));
        $this->plisio->least = '0.10';

        // Out of reach: cURL's message names the address, its key included — never in the shop's message.
        $payment = $this->topUp('50000', $this->plisioMethod('Plisio 4'))->payment()->refresh();
        $this->plisio->down();
        try {
            $this->service(OnlinePayments::class)->check($payment);
            self::fail('out of reach');
        } catch (GatewayUnavailableException $e) {
            self::assertFalse($e->held);
            self::assertStringContainsString('Plisio is out of reach: cURL error 28', $e->detail);
            self::assertStringNotContainsString(FakePlisio::KEY, $e->detail);
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    private function topUp(string $amount, ?PaymentMethod $method = null): CheckoutResult
    {
        $orders = $this->service(OrderService::class);

        return $this->service(Checkout::class)->pay($this->customer, $method ?? $this->method, OrderType::WalletTopUp, $amount, fn(?OrderKey $key): Order => $orders->openTopUp($this->customer, (int) $amount, $key));
    }

    private function callbackPath(Payment $payment): string
    {
        return (string) parse_url($this->service(OnlineInvoices::class)->callbackUrl($payment), PHP_URL_PATH);
    }
}
