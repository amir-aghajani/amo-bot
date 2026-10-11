<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Http\CustomerAuthMiddleware;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * First-purchase prices on the website: shown to guests and to customers who have bought nothing, charged once, a bad
 * token a 401 (never a guest's catalogue), repeated requests at their own price, renewals at the plan's price.
 */
final class StoreFirstPurchasePricesTest extends HttpTestCase
{
    private Website $website;
    private Server $server;
    private Plan $plan;
    private Plan $plain;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan(['price' => '150000.00', 'first_purchase_price' => '50000.00'], $this->server);
        $this->plain = $this->plan(['name' => 'سه‌ماهه', 'price' => '300000.00'], $this->server);
        $this->ali = $this->wallet($this->customer(['username' => 'ali']), '500000.00');
    }

    public function testAGuestReadsEveryPlanWithItsFirstPurchasePrice(): void
    {
        $this->buy($this->ali, $this->plain, $this->server);

        self::assertSame([[$this->plan->id, '150000.00', '50000.00'], [$this->plain->id, '300000.00', null]], $this->catalogue(), 'a guest is no customer who has bought');
        $one = $this->get($this->storeApi($this->website, "/plans/{$this->plan->id}"));
        self::assertSame([200, '50000.00'], [$one->getStatusCode(), $this->decode($one)['plan']['first_purchase_price'] ?? null]);
    }

    public function testSignedInTheFirstPurchasePriceIsTheirsUntilTheyHaveBought(): void
    {
        $this->bearer($this->customerSession($this->ali));
        // A card order still to be paid is no purchase.
        self::assertSame('transfer', $this->decode($this->purchase($this->plain, $this->cardMethod(), 'card-1'))['checkout']['outcome'] ?? null);
        self::assertSame([[$this->plan->id, '150000.00', '50000.00'], [$this->plain->id, '300000.00', null]], $this->catalogue(), 'one who has bought nothing yet');

        $this->buy($this->ali, $this->plain, $this->server);

        self::assertSame([[$this->plan->id, '150000.00', null], [$this->plain->id, '300000.00', null]], $this->catalogue(), 'once they have bought, it is not theirs');
        $one = $this->get($this->storeApi($this->website, "/plans/{$this->plan->id}"));
        self::assertSame(200, $one->getStatusCode(), 'still on sale to them');
        self::assertNull($this->decode($one)['plan']['first_purchase_price'], 'at its price');

        $this->bearer(null);
        self::assertSame('50000.00', $this->catalogue()[0][2], 'their guests still read it');
    }

    public function testATokenThatOpensNoSessionIsA401NeverTheGuestsCatalogue(): void
    {
        $this->bearer(str_repeat('ab', 32));
        foreach (['/plans', "/plans/{$this->plan->id}"] as $path) {
            $response = $this->get($this->storeApi($this->website, $path));

            self::assertSame([401, CustomerAuthMiddleware::SIGNED_OUT, 'Bearer'], [$response->getStatusCode(), $this->decode($response)['message'], $response->getHeaderLine('WWW-Authenticate')], $path);
        }

        $this->bearer($this->customerSession($this->ali));
        $this->ali->forceFill(['status' => UserStatus::Banned])->save();
        $response = $this->get($this->storeApi($this->website, '/plans'));
        self::assertSame([403, SignInRefusedException::BANNED], [$response->getStatusCode(), $this->decode($response)['message']], 'a banned customer, as on any of their own reads');
    }

    public function testTheirFirstPurchaseIsChargedAtItThenThePlansPrice(): void
    {
        $this->bearer($this->customerSession($this->ali));

        $first = $this->purchase($this->plan, $this->walletMethod(), 'first-1');
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $checkout = $this->decode($first)['checkout'];
        self::assertSame(['settled', 'fulfilled', '50000.00'], [$checkout['outcome'], $checkout['order']['status'], $checkout['order']['amount']], 'their first purchase, at its price');
        self::assertSame('450000.00', $this->ali->balance());

        $next = $this->decode($this->purchase($this->plan, $this->walletMethod(), 'first-2'))['checkout'];
        self::assertSame(['settled', '150000.00'], [$next['outcome'], $next['order']['amount']], 'the next one at the plan\'s price');
        self::assertSame('300000.00', $this->ali->balance());

        $madeAgain = $this->decode($this->purchase($this->plan, $this->walletMethod(), 'first-1'))['checkout'];
        self::assertSame([$checkout['order']['id'], '50000.00'], [$madeAgain['order']['id'], $madeAgain['order']['amount']], 'made again with its key: its own order, at its own price');
        self::assertSame('300000.00', $this->ali->balance(), 'nothing charged again');
    }

    public function testAWalletThatCoversTheFirstPurchasePriceBuysIt(): void
    {
        $this->wallet($this->ali, '60000.00');
        $this->bearer($this->customerSession($this->ali));

        $first = $this->purchase($this->plan, $this->walletMethod(), 'first-1');

        self::assertSame(['settled', '50000.00'], [$this->decode($first)['checkout']['outcome'] ?? null, $this->decode($first)['checkout']['order']['amount'] ?? null], 'judged by what it costs them, not by the plan\'s price');
        self::assertSame('10000.00', $this->ali->balance());
    }

    public function testItsServiceRenewsAtThePlansPrice(): void
    {
        $this->bearer($this->customerSession($this->ali));
        $service = $this->buy($this->ali, $this->plan, $this->server)->subscription ?? self::fail('nothing delivered');
        self::assertSame(OrderPricing::FirstPurchase, Order::query()->sole()->pricing);

        $read = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$service->id}")))['subscription'];
        self::assertSame([true, true], [$read['renewable'], $read['auto_renew']['offered']]);

        $preview = $this->get($this->storeApi($this->website, "/subscriptions/{$service->id}/renewal"));
        self::assertSame([200, '150000.00'], [$preview->getStatusCode(), $this->decode($preview)['renewal']['price'] ?? null]);
    }

    /** @return list<array{int, string, string|null}> The plans GET /plans offers, each with its price and its first-purchase price. */
    private function catalogue(): array
    {
        $response = $this->get($this->storeApi($this->website, '/plans'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $plans = [];
        foreach ($this->decode($response)['groups'] as $group) {
            foreach ($group['plans'] as $plan) {
                $plans[] = [$plan['id'], $plan['price'], $plan['first_purchase_price']];
            }
        }

        return $plans;
    }

    private function purchase(Plan $plan, PaymentMethod $method, string $key): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, '/orders'), ['plan_id' => $plan->id, 'server_id' => $this->server->id, 'method_id' => $method->id], ['Idempotency-Key' => $key]);
    }
}
