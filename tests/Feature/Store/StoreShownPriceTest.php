<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Exceptions\CheckoutRefusedException;
use App\Modules\Store\Models\Website;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * A website's checkout naming the amount it showed (`expected_amount`) is paid at that amount alone, as the bot pays
 * only the checkout on screen: one priced otherwise since the page was drawn — a sale over, a price changed — is a 409
 * saying the price now, nothing ordered or charged; a request made again is held to its order's own amount; left out,
 * the price of the moment is paid, as before.
 */
final class StoreShownPriceTest extends HttpTestCase
{
    private Website $website;

    private User $ali;

    private Server $server;

    private Plan $plan;

    private PaymentMethod $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan([], $this->server);
        $this->ali = $this->wallet($this->customer(['username' => 'ali']), '500000');
        $this->wallet = $this->walletMethod();
        $this->bearer($this->customerSession($this->ali));
    }

    public function testAPurchaseShownOnSaleIsNotChargedOnceTheSaleIsOver(): void
    {
        $this->sale('حراج', 20, ['ends_at' => '2026-10-07 12:05:00']);
        $shown = $this->decode($this->get($this->storeApi($this->website, "/plans/{$this->plan->id}")))['plan']['offer_price'];
        self::assertSame('96000.00', $shown);
        Carbon::setTestNow('2026-10-07 12:06:00');

        $refused = $this->placeOrder($this->website, $this->plan, $this->server, $this->wallet, ['expected_amount' => $shown]);

        $this->assertRepriced('120000', $refused);
        self::assertSame(0, Order::query()->count(), 'nothing ordered');
        self::assertSame('500000.00', $this->ali->balance(), 'nothing charged');

        // Shown again at its price now, it is paid — under the same key, which the refusal kept for nothing.
        $paid = $this->placeOrder($this->website, $this->plan, $this->server, $this->wallet, ['expected_amount' => '۱۲۰٬۰۰۰']);
        self::assertSame(['settled', '120000.00'], [$this->decode($paid)['checkout']['outcome'] ?? null, $this->decode($paid)['checkout']['order']['amount'] ?? null], (string) $paid->getBody());
        self::assertSame('380000.00', $this->ali->balance());
    }

    public function testLeftOutThePriceOfTheMomentIsPaidAsBefore(): void
    {
        $this->sale('حراج', 20, ['ends_at' => '2026-10-07 12:05:00']);
        Carbon::setTestNow('2026-10-07 12:06:00');

        foreach (['left-out' => [], 'null' => ['expected_amount' => null], 'blank' => ['expected_amount' => ' ']] as $why => $fields) {
            $response = $this->placeOrder($this->website, $this->plan, $this->server, $this->wallet, $fields, "key-{$why}");
            self::assertSame(['settled', '120000.00'], [$this->decode($response)['checkout']['outcome'] ?? null, $this->decode($response)['checkout']['order']['amount'] ?? null], $why);
        }
    }

    public function testAnAmountThatIsNoWholeTomanIsRefusedUnderItsField(): void
    {
        foreach (['abc', '120000.5', '-120000'] as $i => $sent) {
            $response = $this->placeOrder($this->website, $this->plan, $this->server, $this->wallet, ['expected_amount' => $sent], "key-{$i}");

            self::assertSame(422, $response->getStatusCode(), $sent);
            self::assertSame(['expected_amount'], array_keys($this->decode($response)['errors'] ?? []), $sent);
        }
        self::assertSame(0, Order::query()->count(), 'nothing ordered');
        self::assertSame(200, $this->placeOrder($this->website, $this->plan, $this->server, $this->wallet, ['expected_amount' => 120000])->getStatusCode(), 'a JSON number, as the bot reads one');
    }

    public function testARequestMadeAgainIsHeldToItsOrdersOwnAmount(): void
    {
        $card = $this->cardMethod();
        $this->sale('حراج', 20, ['ends_at' => '2026-10-07 12:05:00']);
        $first = $this->placeOrder($this->website, $this->plan, $this->server, $card, ['expected_amount' => '96000.00']);
        self::assertSame('transfer', $this->decode($first)['checkout']['outcome'] ?? null, (string) $first->getBody());
        Carbon::setTestNow('2026-10-07 12:06:00');

        // Its order waits for the receipt at the sale's price, whatever the plan costs now: that is what is paid.
        $again = $this->placeOrder($this->website, $this->plan, $this->server, $card, ['expected_amount' => '96000.00']);
        self::assertSame(['transfer', '96000.00'], [$this->decode($again)['checkout']['outcome'] ?? null, $this->decode($again)['checkout']['transfer']['amount'] ?? null], (string) $again->getBody());
        $this->assertRepriced('96000', $this->placeOrder($this->website, $this->plan, $this->server, $card, ['expected_amount' => '120000.00']));
        self::assertSame(1, Order::query()->count(), 'one order');
    }

    public function testARenewalIsPaidAtTheAmountShownAndNoOther(): void
    {
        $subscription = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $shown = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}/renewal")))['renewal']['price'];
        $this->plan->forceFill(['price' => '150000.00'])->save();

        $this->assertRepriced('150000', $this->renewing($subscription, $shown));
        self::assertSame(0, Order::query()->count(), 'nothing ordered');

        self::assertSame('settled', $this->decode($this->renewing($subscription, '150000.00'))['checkout']['outcome'] ?? null);
        self::assertSame('350000.00', $this->ali->balance());
    }

    public function testAnExtraIsPaidAtTheAmountShownAndNoOther(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        FakeProvider::mirror($subscription);
        $this->extrasOnSale();
        $shown = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}/extras")))['extras']['traffic']['options'][0];
        self::assertSame(['quantity' => 10, 'price' => '18000.00', 'offer_price' => '18000.00'], $shown);
        $this->extrasOnSale(['extra_traffic_options' => [['quantity' => 10, 'price' => '20000']]]);

        $this->assertRepriced('20000', $this->extraTraffic($subscription, $shown['offer_price']));
        self::assertSame(0, Order::query()->count(), 'nothing ordered');

        self::assertSame('settled', $this->decode($this->extraTraffic($subscription, '20000'))['checkout']['outcome'] ?? null);
    }

    public function testABuiltServiceIsPaidAtTheAmountShownAndNoOther(): void
    {
        $this->customServicesOn([$this->server]);
        $quote = fn(): string => $this->decode($this->send('POST', $this->storeApi($this->website, '/custom-services/quote'), ['gb' => 10, 'days' => 30]))['quote']['amount'];
        $shown = $quote();
        self::assertSame('75000.00', $shown, '10 GB at 3,000 and 30 days at 1,500');
        $this->customServicesOn([$this->server], ['custom_traffic_tiers' => [['from' => 1, 'price' => '3500']]]);

        $this->assertRepriced('80000', $this->building($shown));
        self::assertSame(0, Order::query()->count(), 'nothing ordered');

        self::assertSame('settled', $this->decode($this->building($quote()))['checkout']['outcome'] ?? null);
    }

    /** The checkout refused as priced otherwise than shown: a 409 on `expected_amount`, saying `$now`. */
    private function assertRepriced(string $now, ResponseInterface $response): void
    {
        $words = sprintf(CheckoutRefusedException::REPRICED, Money::format($now));

        self::assertSame([409, $words, ['expected_amount' => [$words]]], [$response->getStatusCode(), $this->decode($response)['message'] ?? null, $this->decode($response)['errors'] ?? null], (string) $response->getBody());
    }

    private function renewing(Subscription $subscription, string $shown): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$subscription->id}/renewal"), ['method_id' => $this->wallet->id, 'expected_amount' => $shown], ['Idempotency-Key' => 'renew-1']);
    }

    private function extraTraffic(Subscription $subscription, string $shown): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$subscription->id}/extra-traffic"), ['gb' => 10, 'method_id' => $this->wallet->id, 'expected_amount' => $shown], ['Idempotency-Key' => 'extra-1']);
    }

    private function building(string $shown): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, '/custom-services/orders'), ['gb' => 10, 'days' => 30, 'method_id' => $this->wallet->id, 'expected_amount' => $shown], ['Idempotency-Key' => 'build-1']);
    }
}
