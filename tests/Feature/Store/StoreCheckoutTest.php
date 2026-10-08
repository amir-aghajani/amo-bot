<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\TooManyUnpaidOrdersException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RequestKey;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Exceptions\CheckoutRefusedException;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerCheckout;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletSettings;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * The checkout on the shop's website — the one the bot's purchases go through (Payments\Services\Checkout), under the
 * bot's rules: the ways to pay a kind of order, a plan as the bot sells it on one of its servers, a top-up within the
 * bot's bounds; nothing ordered until a way to pay is picked, the wallet paying at once (the service in the answer), a
 * card answering where to transfer; a wallet short of the price ordering nothing. A customer is left five unpaid orders
 * at most and sends thirty ordering requests in ten minutes at most. While the bot is switched off, nothing is ordered
 * and no receipt taken.
 */
final class StoreCheckoutTest extends HttpTestCase
{
    /** A PNG of one pixel: a receipt by its bytes. */
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
        $this->card = $this->cardMethod(overrides: ['config' => ['instructions' => 'شماره پیگیری را نگه دارید.']]);
        $this->bearer($this->customerSession($this->customer));
    }

    public function testTheWaysToPayAreTheBotCheckoutsForEachKindOfOrder(): void
    {
        $wallet = $this->walletMethod();
        $this->cardMethod('کارت خاموش', overrides: ['enabled' => false]);
        $this->cardMethod('درگاه قدیمی', overrides: ['driver' => 'paypal']);

        $methods = fn(string $for): array => $this->decode($this->get($this->storeApi($this->website, "/payment-methods?for={$for}")))['methods'] ?? [];

        $both = [['id' => $wallet->id, 'label' => 'کیف پول', 'kind' => 'instant'], ['id' => $this->card->id, 'label' => 'کارت به کارت (ملت)', 'kind' => 'manual']];
        self::assertSame($both, $methods('purchase'), 'every way switched on whose driver is installed, in checkout order');
        self::assertSame($both, $methods('renewal'));
        self::assertSame([['id' => $this->card->id, 'label' => 'کارت به کارت (ملت)', 'kind' => 'manual']], $methods('wallet_topup'), 'the wallet does not charge itself');

        foreach (['', '?for=traffic'] as $query) {
            $response = $this->unchecked()->get($this->storeApi($this->website, "/payment-methods{$query}"));
            self::assertSame(422, $response->getStatusCode(), $query);
            self::assertArrayHasKey('for', $this->decode($response)['errors'], $query);
        }
    }

    public function testAWalletPurchaseIsSettledAtOnceWithItsServiceInTheAnswer(): void
    {
        $this->wallet($this->customer, '500000');

        $response = $this->purchase($this->walletMethod());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $checkout = $this->decode($response)['checkout'];
        $order = Order::query()->sole();
        self::assertSame(['settled', $order->id, 'fulfilled', null], [$checkout['outcome'], $checkout['order']['id'], $checkout['order']['status'], $checkout['transfer']]);
        self::assertSame([['status' => 'paid', 'kind' => 'instant']], array_map(static fn(array $payment): array => ['status' => $payment['status'], 'kind' => $payment['method']['kind']], $checkout['order']['payments']));
        self::assertSame(['id' => $order->subscription_id, 'name' => 'ali_1', 'status' => 'active', 'server' => ['id' => $this->server->id, 'name' => 'Berlin']], array_intersect_key($checkout['subscription'], array_flip(['id', 'name', 'status', 'server'])), 'the service it delivered');
        self::assertNotNull($checkout['subscription']['link']);
        self::assertSame([OrderType::Purchase, $this->plan->id, $this->server->id], [$order->type, $order->plan_id, $order->server_id]);
        self::assertSame([$order->id], RequestKey::query()->where(['user_id' => $this->customer->id, 'key' => 'key-1'])->pluck('order_id')->all(), 'its key kept for the order it came to');
        self::assertSame('380000.00', $this->customer->balance());
        self::assertCount(1, FakeProvider::$created, 'one client on its panel');
        self::assertSame([], $this->telegram()->sentTo(self::TELEGRAM_ID), 'the website shows it: the bot says nothing');
    }

    public function testACardPurchaseAnswersWhereToTransferAndWaitsForTheReceipt(): void
    {
        $response = $this->purchase($this->card);

        $checkout = $this->decode($response)['checkout'];
        $payment = Payment::query()->sole();
        self::assertSame('transfer', $checkout['outcome']);
        self::assertSame([
            'payment_id' => $payment->id,
            'amount' => '120000.00',
            'card' => '6037997700001119',
            'holder' => 'AmoBot',
            'instructions' => 'شماره پیگیری را نگه دارید.',
        ], $checkout['transfer']);
        self::assertSame(['pending', null], [$checkout['order']['status'], $checkout['subscription']]);
        self::assertSame([PaymentStatus::Pending, $this->card->id], [$payment->status, $payment->payment_method_id]);
        self::assertSame([], FakeProvider::$created, 'nothing delivered before the money is in');
    }

    public function testAWalletShortOfThePriceOrdersNothingAndSaysWhatIsMissing(): void
    {
        $this->wallet($this->customer, '50000');

        $response = $this->purchase($this->walletMethod());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(sprintf(CheckoutRefusedException::SHORT, Money::format(70000)), $this->decode($response)['errors']['method_id'][0] ?? null);
        self::assertSame(0, Order::query()->count(), 'nothing ordered');
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testAnAgentsCreditPaysAsItDoesInTheBot(): void
    {
        $agent = $this->wallet($this->agent(credit: '100000', overrides: ['telegram_id' => 7171]), '30000');
        $this->bearer($this->customerSession($agent));

        $response = $this->purchase($this->walletMethod());

        self::assertSame('settled', $this->decode($response)['checkout']['outcome'] ?? null, (string) $response->getBody());
        self::assertSame('-90000.00', $agent->balance(), 'into their credit');
    }

    public function testThePlanAndItsServerAreTheBotsToJudge(): void
    {
        $refused = function (array $body): array {
            $response = $this->send('POST', $this->storeApi($this->website, '/orders'), $body + ['method_id' => $this->card->id], ['Idempotency-Key' => bin2hex(random_bytes(8))]);
            self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());

            return $this->decode($response)['errors'];
        };

        $off = $this->plan(['name' => 'خاموش', 'is_active' => false], $this->server);
        self::assertSame([CustomerCheckout::NOT_ON_SALE], $refused(['plan_id' => $off->id, 'server_id' => $this->server->id])['plan_id'] ?? null, 'switched off');
        self::assertSame([CustomerCheckout::NOT_ON_SALE], $refused(['plan_id' => 999, 'server_id' => $this->server->id])['plan_id'] ?? null, 'none such');

        $paris = $this->sellingServer('Paris');
        self::assertArrayHasKey('server_id', $refused(['plan_id' => $this->plan->id, 'server_id' => $paris->id]), 'not one of its servers');
        $tehran = $this->sellingServer('Tehran');
        $this->planEntry($this->plan, $tehran);
        $this->server->forceFill(['is_active' => false])->save();
        self::assertSame(['server_id'], array_keys($refused(['plan_id' => $this->plan->id, 'server_id' => $this->server->id])), 'its server switched off, another of its locations still selling');
        $tehran->forceFill(['is_active' => false])->save();
        self::assertSame(['plan_id' => [CustomerCheckout::NOT_ON_SALE]], $refused(['plan_id' => $this->plan->id, 'server_id' => $this->server->id]), 'no location left that can deliver it: the plan is not on sale');

        self::assertSame(0, Order::query()->count(), 'nothing ordered');
    }

    public function testInAnAgentsShopAPlanTheirTrafficDoesNotCoverIsNotOnSale(): void
    {
        $bot = $this->agentBot(traffic: 20);
        [$website, $token, $big] = CurrentBot::run($bot, function (): array {
            $big = $this->plan(['name' => 'big', 'traffic_gb' => 50], $this->server);
            $this->cardMethod();

            return [$this->website(), $this->customerSession($this->customer(['telegram_id' => 8181])), $big];
        });
        $this->bearer($token);
        $card = CurrentBot::run($bot, static fn(): PaymentMethod => PaymentMethod::query()->where('driver', 'manual')->firstOrFail());

        $response = $this->send('POST', $this->storeApi($website, '/orders'), ['plan_id' => $big->id, 'server_id' => $this->server->id, 'method_id' => $card->id], ['Idempotency-Key' => 'agent-1']);

        self::assertSame([422, [CustomerCheckout::NOT_ON_SALE]], [$response->getStatusCode(), $this->decode($response)['errors']['plan_id'] ?? null], 'their 20 GB do not cover its 50');
    }

    public function testAWayToPayThatMayNotPayIsRefused(): void
    {
        $off = $this->cardMethod('کارت خاموش', overrides: ['enabled' => false]);
        // Its driver no longer installed: switched on, but nothing to pay with.
        $gone = $this->cardMethod('درگاه قدیمی', overrides: ['driver' => 'paypal']);

        foreach (['switched off' => $off, 'its driver gone' => $gone] as $why => $method) {
            $response = $this->purchase($method, "key-{$method->id}");

            self::assertSame(422, $response->getStatusCode(), $why);
            self::assertArrayHasKey('method_id', $this->decode($response)['errors'], $why);
        }
        self::assertSame(0, Order::query()->count(), 'nothing ordered');
    }

    public function testATopUpIsHeldToTheBotsBoundsAndPaidAnyWayButTheWallet(): void
    {
        $response = $this->topUp('۵۰٬۰۰۰ تومان', $this->card);

        $checkout = $this->decode($response)['checkout'];
        self::assertSame(['transfer', '50000.00', 'wallet_topup'], [$checkout['outcome'], $checkout['transfer']['amount'] ?? null, $checkout['order']['type']], 'typed as the bot reads a typed amount');

        $bounds = 'مبلغ شارژ باید بین ' . Money::format(10000) . ' و ' . Money::format(WalletSettings::TOPUP_MAX) . ' باشد.';
        foreach (['5000', (string) (WalletSettings::TOPUP_MAX + 1), 'پنج هزار', '1.5'] as $i => $amount) {
            $response = $this->topUp($amount, $this->card, "bounds-{$i}");
            self::assertSame([422, [$bounds]], [$response->getStatusCode(), $this->decode($response)['errors']['amount'] ?? null], $amount);
        }

        $response = $this->topUp(50000, $this->walletMethod(), 'with-the-wallet');
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['کیف پول با خودش شارژ نمی‌شود؛ روش دیگری انتخاب کنید.'], $this->decode($response)['errors']['method_id'] ?? null);
        self::assertSame(1, Order::query()->count(), 'the first alone');
    }

    public function testTheWebsiteLeavesACustomerFiveUnpaidOrdersAtMost(): void
    {
        // Five unpaid already — made in the bot, or on the website —: a sixth is not made.
        foreach (range(1, OrderService::UNPAID_MAX) as $n) {
            $this->topUpOrder($this->customer, ($n * 10000) . '.00');
        }

        $response = $this->purchase($this->card);

        self::assertSame([422, TooManyUnpaidOrdersException::MESSAGE], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame(OrderService::UNPAID_MAX, Order::query()->count(), 'nothing ordered');

        // One of them asked for again is found, not made: paid as ever.
        $again = $this->topUp(10000, $this->card, 'the-first-top-up-again');
        self::assertSame('transfer', $this->decode($again)['checkout']['outcome'] ?? null, (string) $again->getBody());
        self::assertSame(OrderService::UNPAID_MAX, Order::query()->count());

        // One that went makes room for another.
        Order::query()->where('amount', '50000.00')->update(['status' => OrderStatus::Cancelled->value]);
        self::assertSame('transfer', $this->decode($this->purchase($this->card))['checkout']['outcome'] ?? null);

        // The bot's checkout is not held to it: a chat picks one way to pay at a time.
        $this->service(OrderService::class)->openTopUp($this->customer, 70000);
        self::assertSame(OrderService::UNPAID_MAX + 1, Order::query()->where('status', OrderStatus::Pending->value)->count());
    }

    public function testACustomerSendsThirtyOrderingRequestsInTenMinutesAtMost(): void
    {
        // A request made again counts: each asks the shop as much.
        foreach (range(1, CustomerCheckout::CHECKOUTS) as $n) {
            self::assertSame(200, $this->purchase($this->card)->getStatusCode(), "request {$n}");
        }

        $refused = $this->topUp(50000, $this->card, 'one-more');

        self::assertSame([429, '600'], [$refused->getStatusCode(), $refused->getHeaderLine('Retry-After')]);
        self::assertSame('درخواست پرداخت زیادی فرستاده‌اید؛ ۱۰ دقیقه دیگر دوباره امتحان کنید.', $this->decode($refused)['message']);
        self::assertSame(1, Order::query()->count(), 'the top-up not made');

        Carbon::setTestNow(now()->addMinutes(10));
        self::assertSame(200, $this->topUp(50000, $this->card, 'one-more')->getStatusCode(), 'the window over');
    }

    public function testWhileTheBotIsSwitchedOffTheShopTakesNoOrders(): void
    {
        $this->wallet($this->customer, '500000');
        $service = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        $transfer = $this->decode($this->purchase($this->card))['checkout']['transfer'];
        $this->botSettings('general', ['enabled' => false, 'phone_required' => false, 'support_contact' => '']);

        foreach ([
            'a purchase' => $this->purchase($this->walletMethod(), 'key-2'),
            'a renewal' => $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$service->id}/renewal"), ['method_id' => $this->card->id], ['Idempotency-Key' => 'renew-1']),
            'a top-up' => $this->topUp(50000, $this->card),
            'a receipt' => $this->upload($this->storeApi($this->website, "/payments/{$transfer['payment_id']}/receipt"), 'file', 'receipt.png', (string) base64_decode(self::PNG)),
        ] as $what => $response) {
            self::assertSame([503, CheckoutRefusedException::PAUSED], [$response->getStatusCode(), $this->decode($response)['message']], $what);
        }
        self::assertSame(1, Order::query()->count(), 'nothing ordered');
        self::assertNull(Payment::query()->sole()->receipt_at, 'no receipt taken');
        self::assertSame(200, $this->get($this->storeApi($this->website, '/plans'))->getStatusCode(), 'what it sells is still read');
        self::assertSame(200, $this->get($this->storeApi($this->website, "/subscriptions/{$service->id}/renewal"))->getStatusCode(), 'and what a renewal would be');

        $this->botSettings('general', ['enabled' => true, 'phone_required' => false, 'support_contact' => '']);
        self::assertSame(200, $this->purchase($this->walletMethod(), 'key-2')->getStatusCode(), 'switched on again');
    }

    public function testACheckoutIsTheSignedInCustomersOwn(): void
    {
        $this->bearer(null);

        $response = $this->unchecked()->send('POST', $this->storeApi($this->website, '/orders'), ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'method_id' => $this->card->id], ['Idempotency-Key' => 'k']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, Order::query()->count());
    }

    private function purchase(PaymentMethod $method, string $key = 'key-1'): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, '/orders'), ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'method_id' => $method->id], ['Idempotency-Key' => $key]);
    }

    private function topUp(int|string $amount, PaymentMethod $method, string $key = 'top-up-1'): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, '/wallet/top-up'), ['amount' => $amount, 'method_id' => $method->id], ['Idempotency-Key' => $key]);
    }
}
