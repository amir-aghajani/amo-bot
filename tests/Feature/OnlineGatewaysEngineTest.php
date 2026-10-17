<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Contracts\OnlineGateway;
use App\Modules\Payments\Drivers\AtlasPay\AtlasPayGateway;
use App\Modules\Payments\Drivers\Zarinpal\ZarinpalGateway;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\DTO\OnlineInvoice;
use App\Modules\Payments\DTO\OnlineRequest;
use App\Modules\Payments\DTO\OnlineVerdict;
use App\Modules\Payments\DTO\PaymentInitiation;
use App\Modules\Payments\DTO\PaymentResult;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\OnlineInvoices;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Payments\Tasks\OnlinePaymentsTask;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Carbon;
use Psr\Http\Message\RequestInterface;
use Tests\HttpTestCase;

/**
 * The online payments' engine through the gateways OnlinePaymentsTest's CubePay does not show it on: one page per
 * payment — Zarinpal makes a new authority for every request, so two openings of one payment at once both show the one
 * the shop kept —, a page's end past what the shop keeps held to its last moment, and money a gateway holds on a payment
 * still under way (AtlasPay's review, Aban's part paid) marked on it once, its payment never given up on while the
 * gateway may still settle it.
 */
final class OnlineGatewaysEngineTest extends HttpTestCase
{
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example']);
        $this->telegram();
        $this->customer = $this->customer(['username' => 'ali_1']);
    }

    public function testTwoOpeningsOfOnePaymentAtOnceShowTheOnePageTheShopKeeps(): void
    {
        $logs = $this->logs();
        $zarinpal = $this->zarinpal();
        $method = $this->zarinpalMethod();
        $order = $this->service(OrderService::class)->openTopUp($this->customer, 50000);
        $payment = Payment::query()->create(['order_id' => $order->id, 'payment_method_id' => $method->id, 'status' => PaymentStatus::Pending, 'amount' => $order->amount]);
        $gateway = $this->service(GatewayRegistry::class)->forMethod($method);
        self::assertInstanceOf(OnlineGateway::class, $gateway);

        // Both read the payment before either kept a page; the one whose answer came first is kept.
        $slow = Payment::query()->with('order.user', 'method')->findOrFail($payment->id);
        $fast = Payment::query()->with('order.user', 'method')->findOrFail($payment->id);
        $invoices = $this->service(OnlineInvoices::class);
        $invoices->open($fast, $gateway);
        $invoices->open($slow, $gateway);

        self::assertCount(2, $zarinpal->payments, 'Zarinpal made an authority for each');
        $kept = $payment->refresh();
        self::assertSame([$kept->link, $kept->reference], [$fast->link, $fast->reference]);
        self::assertSame([$kept->link, $kept->reference], [$slow->link, $slow->reference], 'the slow one answers with the page kept');
        self::assertSame($kept->link, $gateway->initiate($slow)->link?->url);
        self::assertTrue($logs->hasInfoThatContains('was opened on zarinpal twice at once'));

        // The customer pays the page they were shown: the shop's.
        $zarinpal->pay((string) $kept->reference);
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($kept->load('method')));
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testAPageTheGatewaySaysLastsPastWhatTheShopKeepsIsKeptToItsLastMoment(): void
    {
        $this->zarinpal();
        $method = $this->zarinpalMethod();
        $order = $this->service(OrderService::class)->openTopUp($this->customer, 50000);
        $payment = Payment::query()->create(['order_id' => $order->id, 'payment_method_id' => $method->id, 'status' => PaymentStatus::Pending, 'amount' => $order->amount]);
        $zarinpal = $this->service(GatewayRegistry::class)->forMethod($method);
        self::assertInstanceOf(OnlineGateway::class, $zarinpal);
        // A gateway answering a page open until 2100: past MySQL's TIMESTAMP, it would be refused on saving.
        $gateway = new class ($zarinpal) implements OnlineGateway {
            public function __construct(private readonly OnlineGateway $inner) {}

            public function open(Payment $payment, OnlineRequest $request): OnlineInvoice
            {
                $invoice = $this->inner->open($payment, $request);

                return new OnlineInvoice($invoice->url, new \DateTimeImmutable('2100-01-01T00:00:00Z'), $invoice->reference, $invoice->details);
            }

            public function check(Payment $payment): OnlineVerdict
            {
                return $this->inner->check($payment);
            }

            public function initiate(Payment $payment): PaymentInitiation
            {
                return $this->inner->initiate($payment);
            }

            public function settle(Payment $payment): PaymentResult
            {
                return $this->inner->settle($payment);
            }
        };

        $this->service(OnlineInvoices::class)->open(Payment::query()->with('order.user', 'method')->findOrFail($payment->id), $gateway);

        $kept = $payment->refresh();
        self::assertNotNull($kept->link);
        self::assertSame(LocalTime::last()->getTimestamp(), $kept->link_expires_at?->getTimestamp());
    }

    public function testARequestMadeAgainWhileTheFirstWaitsOnTheGatewayAnswersTheSamePage(): void
    {
        $zarinpal = $this->zarinpal();
        $method = $this->zarinpalMethod();
        $again = null;
        // The website's request made again with its key reaches the shop while Zarinpal has yet to answer the first.
        $transport = $this->app()->container()->get('http.transport');
        self::assertInstanceOf(HandlerStack::class, $transport);
        $transport->setHandler(function (RequestInterface $request, array $options) use ($zarinpal, $method, &$again): PromiseInterface {
            $answer = $zarinpal($request, $options);
            if ($again === null && str_ends_with((string) $request->getUri(), ZarinpalGateway::REQUEST)) {
                $again = false;
                $again = $this->topUp($method, 'same-key');
            }

            return $answer;
        });

        $first = $this->topUp($method, 'same-key');

        self::assertInstanceOf(CheckoutResult::class, $again);
        self::assertSame(1, Payment::query()->count());
        self::assertCount(2, $zarinpal->payments);
        $kept = Payment::query()->sole();
        self::assertSame($kept->link, $first->payLink()->url);
        self::assertSame($kept->link, $again->payLink()->url, 'both answers are the page the shop asks about');
    }

    public function testMoneyInReviewAtTheGatewayIsMarkedOnceAndNeverGivenUpOn(): void
    {
        $logs = $this->logs();
        // An http shop: AtlasPay is given no address to call, the task is all that asks.
        $this->config(['app.url' => 'http://shop.example']);
        $atlas = $this->atlasPay();
        $payment = $this->topUp($this->atlasPayMethod())->payment();
        $atlas->review($atlas->onlyOrder());
        $online = $this->service(OnlinePayments::class);

        self::assertSame(OnlineState::Waiting, $online->check($payment->refresh()));
        self::assertTrue(OnlinePayments::hasMoneyIn($payment->refresh()));
        self::assertSame(OnlineState::Waiting, $online->check($payment));
        self::assertCount(1, array_filter($logs->getRecords(), static fn($record): bool => str_contains($record->message, 'has money in at its gateway')), 'marked once');

        // Hours after its page closed, still AtlasPay's to decide: asked, never closed.
        $task = $this->service(OnlinePaymentsTask::class);
        foreach (range(1, 20) as $step) {
            Carbon::setTestNow(now()->addMinutes(OnlinePaymentsTask::LATE_CHECK_MINUTES));
            $task->run();
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        // AtlasPay confirms it: the task finds it.
        $atlas->pay($atlas->onlyOrder());
        Carbon::setTestNow(now()->addMinutes(OnlinePaymentsTask::LATE_CHECK_MINUTES));
        $task->run();
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testAnInvoicePaidInPartIsMarkedAsMoneyIn(): void
    {
        $aban = $this->aban();
        $payment = $this->topUp($this->abanMethod())->payment();
        $aban->payPart($aban->onlyInvoice());

        self::assertSame(OnlineState::Waiting, $this->service(OnlinePayments::class)->check($payment->refresh()));
        self::assertTrue(OnlinePayments::hasMoneyIn($payment->refresh()));
        self::assertSame(PaymentStatus::Pending, $payment->status);
    }

    public function testATapTheGatewayRefusedAsAnotherOpenedThePageLeavesThatPageLive(): void
    {
        $atlas = $this->atlasPay();
        $method = $this->atlasPayMethod();
        $second = null;
        $transport = $this->app()->container()->get('http.transport');
        self::assertInstanceOf(HandlerStack::class, $transport);
        // The second tap arrives while AtlasPay makes the first's order: AtlasPay refuses its repeated reference.
        $transport->setHandler(function (RequestInterface $request, array $options) use ($atlas, $method, &$second): PromiseInterface {
            $answer = $atlas($request, $options);
            if ($second === null && (string) $request->getUri() === AtlasPayGateway::ORDERS) {
                $second = false;
                $second = $this->topUp($method);
            }

            return $answer;
        });

        $first = $this->topUp($method);

        self::assertInstanceOf(CheckoutResult::class, $second);
        self::assertSame([CheckoutOutcome::Link, CheckoutOutcome::Refused], [$first->outcome, $second->outcome]);
        $payment = Payment::query()->sole();
        self::assertSame([PaymentStatus::Pending, null, true], [$payment->status, $payment->note, $payment->hasOpenPage()], 'the page shown is the payment\'s, open');

        $atlas->pay($atlas->onlyOrder());
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame('50000.00', $this->customer->balance());
    }

    private function topUp(PaymentMethod $method, ?string $key = null): CheckoutResult
    {
        $orders = $this->service(OrderService::class);

        return $this->service(Checkout::class)->pay($this->customer, $method, OrderType::WalletTopUp, '50000', fn(?OrderKey $key): Order => $orders->openTopUp($this->customer, 50000, $key), $key);
    }
}
