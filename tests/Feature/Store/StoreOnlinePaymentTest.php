<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Drivers\CubePay\CubePayGateway;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Exceptions\PaymentHeldException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\OnlineInvoices;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Exceptions\CheckoutRefusedException;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerOnlinePayments;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeAban;
use Tests\Support\FakeCubePay;

/**
 * An online gateway (CubePay) on the website: the checkout answers its page (`link`) — the exact sum it asks, until when,
 * the card when the gateway shows one, its holder and bank as named —, a request made again the same page, and no
 * receipt is taken for it; `POST /payments/{id}/check` asks the gateway and settles or closes the payment as it says, for
 * the customer's own online payment alone, a few times a minute — the gateway out of reach in its own words, a payment
 * held for support a 422 sending the customer there —; the gateway's callback settles a website customer's payment and
 * tells them.
 */
final class StoreOnlinePaymentTest extends HttpTestCase
{
    /** A PNG of one pixel: a receipt by its bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private FakeCubePay $cubePay;
    private Website $website;
    private User $customer;
    private Server $server;
    private Plan $plan;
    private PaymentMethod $online;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->withoutQr();
        $this->config(['app.url' => 'https://shop.example']);
        $this->cubePay = $this->cubePay();
        $this->website = $this->website();
        $this->customer = $this->customer(['username' => 'ali']);
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan([], $this->server);
        $this->online = $this->cubePayMethod();
        $this->bearer($this->customerSession($this->customer));
    }

    public function testAFeePassedOnToCustomersIsInTheSumAskedAndNamedApart(): void
    {
        $this->online->forceFill(['config' => ['fee_percent' => '', 'fee_fixed' => 1000] + $this->online->config])->save();

        $topUp = $this->checkout($this->placeTopUp($this->website, 30000, $this->online));

        self::assertSame(['31037.00', '1000.00'], [$topUp['link']['amount'], $topUp['link']['fee']]);
        self::assertSame(['30000.00', '30000.00'], [$topUp['order']['amount'], $topUp['order']['payments'][0]['amount']], 'the order and its payment at their own price');
    }

    public function testATopUpAndAPurchaseAnswerTheGatewaysPageAndTheExactSumItAsks(): void
    {
        $methods = $this->decode($this->get($this->storeApi($this->website, '/payment-methods?for=wallet_topup')))['methods'];
        self::assertSame([['id' => $this->online->id, 'label' => 'پرداخت آنلاین', 'kind' => 'online', 'min_amount' => null, 'max_amount' => null]], $methods);

        $topUp = $this->checkout($this->placeTopUp($this->website, 50000, $this->online));
        $payment = Payment::query()->sole();
        self::assertSame(['link', null], [$topUp['outcome'], $topUp['transfer']]);
        self::assertSame(['payment_id' => $payment->id, 'url' => $payment->link, 'amount' => '50037.00', 'fee' => null, 'card' => null], array_diff_key($topUp['link'], ['expires_at' => true]));
        self::assertStringStartsWith('https://cubevps.ir/', $topUp['link']['url']);
        self::assertStringEndsWith('+00:00', (string) $topUp['link']['expires_at'], 'in UTC, as every moment of the API');
        self::assertTrue(Carbon::parse($topUp['link']['expires_at'])->between(now()->addMinutes(29), now()->addMinutes(31)), 'until the page closes');
        self::assertSame(['pending', 'pending', '50000.00'], [$topUp['order']['status'], $topUp['order']['payments'][0]['status'], $topUp['order']['payments'][0]['amount']]);
        self::assertSame($topUp['link'], $topUp['order']['payments'][0]['link'], 'the order shows the page while it takes money');

        $purchase = $this->checkout($this->placeOrder($this->website, $this->plan, $this->server, $this->online));
        self::assertSame(['link', '120037.00'], [$purchase['outcome'], $purchase['link']['amount']], 'the price and the few Toman the gateway adds');
        self::assertSame($purchase['link'], $this->decode($this->get($this->storeApi($this->website, "/orders/{$purchase['order']['id']}")))['order']['payments'][0]['link']);

        // A page closed, or a payment no longer waiting on it, shows none.
        Payment::query()->whereKey($purchase['link']['payment_id'])->update(['link_expires_at' => now()->subMinute()]);
        Payment::query()->whereKey($topUp['link']['payment_id'])->update(['status' => PaymentStatus::Cancelled->value]);
        $orders = $this->decode($this->get($this->storeApi($this->website, '/orders')))['orders'];
        self::assertSame([null, null], [$orders[0]['payments'][0]['link'], $orders[1]['payments'][0]['link']]);
    }

    public function testTheCardTheGatewayShowsComesWithThePage(): void
    {
        $this->cubePay->showCard = true;

        $link = $this->checkout($this->placeTopUp($this->website, 50000, $this->online))['link'];

        self::assertSame(['number' => FakeCubePay::CARD, 'holder' => 'CubePay Holder', 'bank' => null], $link['card']);
        self::assertSame('50037.00', $link['amount']);
    }

    public function testACardComesWithItsHolderAndBankAsTheGatewayNamedThem(): void
    {
        $this->aban();
        $aban = $this->abanMethod();

        $link = $this->checkout($this->placeTopUp($this->website, 50000, $aban))['link'];

        self::assertSame(['number' => FakeAban::CARD, 'holder' => 'سارا رضایی', 'bank' => 'ملی'], $link['card']);
    }

    public function testARequestMadeAgainWithItsKeyAnswersTheSamePageWithoutOpeningAnother(): void
    {
        $first = $this->checkout($this->placeOrder($this->website, $this->plan, $this->server, $this->online, key: 'online-1'));
        $again = $this->checkout($this->placeOrder($this->website, $this->plan, $this->server, $this->online, key: 'online-1'));

        self::assertSame($first['link'], $again['link']);
        self::assertSame($first['order']['id'], $again['order']['id']);
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::CREATE), 'opened on the gateway once');
        self::assertSame(1, Payment::query()->count());
    }

    public function testACheckSaysWaitingUntilThePaymentIsPaidThenSettlesAndDeliversIt(): void
    {
        $link = $this->checkout($this->placeOrder($this->website, $this->plan, $this->server, $this->online))['link'];

        $waiting = $this->check($link['payment_id']);
        self::assertSame(200, $waiting->getStatusCode(), (string) $waiting->getBody());
        $check = $this->decode($waiting)['check'];
        self::assertSame(['waiting', 'pending', null], [$check['state'], $check['order']['status'], $check['subscription']]);
        self::assertSame($link, $check['order']['payments'][0]['link']);

        $this->cubePay->pay($this->cubePay->onlyOrder());
        $check = $this->decode($this->check($link['payment_id']))['check'];

        self::assertSame(['paid', 'fulfilled', 'paid', null], [$check['state'], $check['order']['status'], $check['order']['payments'][0]['status'], $check['order']['payments'][0]['link']]);
        self::assertSame($check['order']['subscription']['id'], $check['subscription']['id'] ?? null, 'the service delivered');
        self::assertSame(1, Notification::query()->where('type', 'payment_settled')->count(), 'the customer is told, as the bot tells them');

        // Asked again: paid, and the gateway is not asked.
        $verified = count($this->cubePay->callsTo(CubePayGateway::VERIFY));
        self::assertSame('paid', $this->decode($this->check($link['payment_id']))['check']['state']);
        self::assertCount($verified, $this->cubePay->callsTo(CubePayGateway::VERIFY));
    }

    public function testAPageClosedUnpaidFailsThePaymentAndLeavesTheOrderOpen(): void
    {
        $link = $this->checkout($this->placeOrder($this->website, $this->plan, $this->server, $this->online))['link'];
        $this->cubePay->close($this->cubePay->onlyOrder());

        $check = $this->decode($this->check($link['payment_id']))['check'];

        self::assertSame(['closed', 'pending', 'failed', PaymentService::NOTE_LINK_CLOSED, null], [$check['state'], $check['order']['status'], $check['order']['payments'][0]['status'], $check['order']['payments'][0]['note'], $check['order']['payments'][0]['link']]);
    }

    public function testMoneyThatCameAfterItsOrderClosedGoesToTheWallet(): void
    {
        $link = $this->checkout($this->placeOrder($this->website, $this->plan, $this->server, $this->online))['link'];
        $this->service(OrderService::class)->cancel(Order::query()->sole(), 'لغو');
        $this->cubePay->pay($this->cubePay->onlyOrder());

        $check = $this->decode($this->check($link['payment_id']))['check'];

        self::assertSame(['paid', 'cancelled', 'paid', PaymentService::NOTE_TO_WALLET], [$check['state'], $check['order']['status'], $check['order']['payments'][0]['status'], $check['order']['payments'][0]['note']]);
        self::assertSame('120000.00', $this->customer->refresh()->balance());
        $notices = $this->decode($this->get($this->storeApi($this->website, '/notifications')))['notifications'];
        self::assertSame(['payment_credited'], array_column($notices, 'type'));
    }

    public function testOnlyTheCustomersOwnOnlinePaymentIsChecked(): void
    {
        $link = $this->checkout($this->placeOrder($this->website, $this->plan, $this->server, $this->online))['link'];
        $card = $this->cardPayment($this->purchaseOrder($this->customer, $this->plan, $this->server), $this->cardMethod());

        $this->assertRefusedUnder('status', CustomerOnlinePayments::NOT_ONLINE, $this->check($card->id));

        $this->bearer($this->customerSession($this->customer(['telegram_id' => 222, 'first_name' => 'Sara'])));
        self::assertSame(404, $this->check($link['payment_id'])->getStatusCode());
        self::assertCount(0, $this->cubePay->callsTo(CubePayGateway::VERIFY), 'the gateway is never asked for them');
    }

    public function testAnOnlinePaymentTakesNoReceipt(): void
    {
        $link = $this->checkout($this->placeOrder($this->website, $this->plan, $this->server, $this->online))['link'];

        $upload = $this->upload($this->storeApi($this->website, "/payments/{$link['payment_id']}/receipt"), 'file', 'receipt.png', (string) base64_decode(self::PNG));

        $this->assertRefusedUnder('status', 'رسید فقط برای پرداخت کارت به کارت فرستاده می‌شود.', $upload);
        self::assertSame([PaymentStatus::Pending, null], [Payment::query()->sole()->status, Payment::query()->sole()->receipt_path]);
    }

    public function testAGatewayOutOfReachIsA503AndChangesNothing(): void
    {
        $link = $this->checkout($this->placeOrder($this->website, $this->plan, $this->server, $this->online))['link'];
        $this->cubePay->down();

        $response = $this->check($link['payment_id']);

        self::assertSame([503, CheckoutRefusedException::UNREACHABLE], [$response->getStatusCode(), $this->decode($response)['message'] ?? null], 'not the checkout\'s «another way to pay»: they may have paid');
        self::assertSame(PaymentStatus::Pending, Payment::query()->sole()->status);
    }

    /**
     * A check whose answer does not fit the payment (CubePay took less) holds it for support: a 422 on `status` sending the
     * customer to support, as every check after it — none asks the gateway again —, its order shows no page to pay on
     * any more, and the order is paid no other way (the checkout's own refusal).
     */
    public function testAPaymentHeldForSupportIsA422SendingTheCustomerToSupport(): void
    {
        $link = $this->checkout($this->placeOrder($this->website, $this->plan, $this->server, $this->online, key: 'held-1'))['link'];
        $order = $this->cubePay->onlyOrder();
        $this->cubePay->orders[$order]['price'] -= 1000;
        $this->cubePay->pay($order);
        $held = sprintf(PaymentHeldException::MESSAGE, Order::query()->sole()->id);

        $this->assertRefusedUnder('status', $held, $this->check($link['payment_id']));
        self::assertTrue(OnlinePayments::isHeld(Payment::query()->sole()));

        $verified = count($this->cubePay->callsTo(CubePayGateway::VERIFY));
        $this->assertRefusedUnder('status', $held, $this->check($link['payment_id']));
        self::assertCount($verified, $this->cubePay->callsTo(CubePayGateway::VERIFY), 'never asked again');

        $shown = $this->decode($this->get($this->storeApi($this->website, '/orders/' . Order::query()->sole()->id)))['order'];
        self::assertSame(['pending', 'pending', null], [$shown['status'], $shown['payments'][0]['status'], $shown['payments'][0]['link']], 'its page no longer offered');

        // Made again with its key: no new page, nothing paid twice.
        $this->assertRefusedUnder('status', $held, $this->placeOrder($this->website, $this->plan, $this->server, $this->online, key: 'held-1'));
        self::assertSame([1, 1], [Payment::query()->count(), count($this->cubePay->callsTo(CubePayGateway::CREATE))]);
    }

    public function testChecksAreHeldToTenAMinute(): void
    {
        $link = $this->checkout($this->placeOrder($this->website, $this->plan, $this->server, $this->online))['link'];

        for ($i = 0; $i < CustomerOnlinePayments::CHECKS; $i++) {
            self::assertSame(200, $this->check($link['payment_id'])->getStatusCode());
        }
        $refused = $this->check($link['payment_id']);

        self::assertSame(429, $refused->getStatusCode());
        self::assertNotSame('', $refused->getHeaderLine('Retry-After'));
        self::assertCount(CustomerOnlinePayments::CHECKS, $this->cubePay->callsTo(CubePayGateway::VERIFY));
    }

    public function testAGatewayThatWillNotOpenThePaymentRefusesTheCheckout(): void
    {
        $this->cubePay->refuse(402, 'کیف پول کارمزد شارژ ندارد.');

        $this->assertRefusedUnder('method_id', GatewayUnavailableException::UNAVAILABLE, $this->placeOrder($this->website, $this->plan, $this->server, $this->online));

        self::assertSame([OrderStatus::Pending, PaymentStatus::Failed], [Order::query()->sole()->status, Payment::query()->sole()->status], 'the order stays open to pay again');
        self::assertSame('link', $this->checkout($this->placeOrder($this->website, $this->plan, $this->server, $this->online))['outcome'], 'made again, a new payment is opened');
    }

    public function testTheGatewaysCallbackSettlesAWebsiteCustomersPaymentAndTellsThem(): void
    {
        $customer = $this->webCustomer();
        $this->bearer($this->customerSession($customer));
        $link = $this->checkout($this->placeTopUp($this->website, 50000, $this->online))['link'];
        $this->cubePay->pay($this->cubePay->onlyOrder());

        $url = $this->service(OnlineInvoices::class)->callbackUrl(Payment::query()->findOrFail($link['payment_id']));
        $callback = $this->send('POST', (string) parse_url($url, PHP_URL_PATH));

        self::assertSame([200, ['paid' => true]], [$callback->getStatusCode(), $this->decode($callback)]);
        self::assertSame('50000.00', $customer->refresh()->balance(), 'the order the top-up was, not the few Toman the gateway added');
        $notices = $this->decode($this->get($this->storeApi($this->website, '/notifications')))['notifications'];
        self::assertSame(['payment_settled'], array_column($notices, 'type'));
        self::assertSame(['type' => 'order', 'id' => Order::query()->sole()->id], $notices[0]['subject']);
    }

    /** `POST /payments/{id}/check` for the signed-in customer. */
    private function check(int $payment): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, "/payments/{$payment}/check"));
    }

    /**
     * A checkout's answer, which must be a 200.
     *
     * @return array<string, mixed>
     */
    private function checkout(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['checkout'];
    }
}
