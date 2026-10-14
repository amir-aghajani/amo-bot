<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Catalog\Services\CustomServices;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerCheckout;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * A service without a plan renewed on the website with a package its customer builds: its choices say whether the
 * builder is one (and the service that it picks), the preview and the renewal take `gb` and `days` in place of
 * `plan_id` — priced by the builder's tiers, its refusals in its words under their field —, a discount code is checked
 * against the package, and the renewal is an order without a plan that leaves the service without one; a request made
 * again is answered by its order.
 */
final class StoreCustomRenewalTest extends HttpTestCase
{
    private Website $website;
    private User $ali;
    private Server $berlin;
    private Subscription $built;
    private PaymentMethod $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->berlin = $this->sellingServer('Berlin');
        $this->customServicesOn([$this->berlin]);
        $this->wallet = $this->walletMethod();
        $this->ali = $this->wallet($this->customer(['username' => 'ali']), '1000000.00');
        // A service ali built: 50 GB for 30 days, 18 GB of it used.
        $this->built = $this->mirrored($this->subscription($this->ali, $this->plan(), $this->berlin, 'ali_1', [
            'plan_id' => null,
            'traffic_limit_bytes' => Traffic::bytesOfGb(50),
            'download_bytes' => Traffic::bytesOfGb(18),
            'ip_limit' => 2,
        ]));
        $this->bearer($this->customerSession($this->ali));
    }

    public function testItsChoicesSayWhetherTheBuilderIsOneAndTheServiceThatItPicks(): void
    {
        self::assertSame(['plans' => [], 'custom_services' => true], $this->choices($this->built), 'no plan on sale on Berlin: the builder alone');
        self::assertSame([null, false, true, false], $this->judged(), 'renewed on what it picks, never by itself');

        $this->customServicesOn([$this->berlin], ['custom_enabled' => false]);
        self::assertSame(['plans' => [], 'custom_services' => false], $this->choices($this->built));
        self::assertSame([null, false, false, false], $this->judged(), 'nothing to pick');

        $this->customServicesOn([$this->berlin]);
        $own = $this->subscription($this->ali, $this->plan([], $this->berlin), $this->berlin, 'ali_2');
        self::assertFalse($this->choices($own)['custom_services'], 'one with its own plan is renewed on it');
        self::assertCount(1, $this->choices($this->built)['plans'], 'a plan on sale beside the builder');
    }

    public function testThePreviewOfAPackageIsPricedByTheBuildersTiers(): void
    {
        $preview = $this->preview(['gb' => 50, 'days' => 30]);

        self::assertSame(200, $preview->getStatusCode(), (string) $preview->getBody());
        $renewal = $this->decode($preview)['renewal'];
        self::assertSame(['extend', null, '170000.00', []], [$renewal['method'], $renewal['plan'], $renewal['price'], $renewal['discounts']]);
        self::assertSame(['gb' => 50, 'days' => 30, 'devices' => 2, 'traffic_price' => '125000.00', 'days_price' => '45000.00'], $renewal['custom']);
        self::assertSame([Traffic::bytesOfGb(100), 60 * 24], [$renewal['after']['traffic']['limit_bytes'], $renewal['after']['term']['duration_hours']], 'its package on top of what it has');
    }

    public function testEachRefusalIsUnderItsField(): void
    {
        self::assertSame(['gb' => ['حجم را عددی بین ۱ تا ۵۰۰ گیگابایت بنویسید.']], $this->refused(['gb' => 501, 'days' => 30]), "the builder's words");
        self::assertSame(['days' => ['مدت را عددی بین ۱ تا ۹۰ روز بنویسید.']], $this->refused(['gb' => 50, 'days' => 91]));
        self::assertSame(['plan_id' => [CustomerCheckout::PLAN_OR_PACKAGE]], $this->refused(['gb' => 50, 'days' => 30, 'plan_id' => 1]));
        self::assertSame(['plan_id' => [CustomerCheckout::PICK_A_PLAN_OR_PACKAGE]], $this->refused([]), 'nothing picked');

        $this->botSettings('renewal', ['renew_window_days' => 7]);
        self::assertSame(['status' => [sprintf(CustomerCheckout::NOT_YET, 'از ' . Persian::date(Carbon::parse('2026-11-04 12:00:00'), withTime: true))]], $this->refused(['gb' => 50, 'days' => 30]), 'its window');
        $this->botSettings('renewal', ['renew_window_days' => 0]);

        $this->customServicesOn([$this->berlin], ['custom_enabled' => false]);
        self::assertSame(['status' => [CustomServices::OFF]], $this->refused(['gb' => 50, 'days' => 30]));
        self::assertSame(['status' => [CustomerCheckout::NOT_RENEWABLE]], $this->refused([]), 'nothing to renew it on');

        $this->customServicesOn([$this->berlin]);
        $own = $this->mirrored($this->subscription($this->ali, $this->plan([], $this->berlin), $this->berlin, 'ali_2'));
        self::assertSame(['gb' => [CustomerCheckout::RENEWS_ON_ITS_PLAN]], $this->refused(['gb' => 50, 'days' => 30], $own));
        self::assertSame(0, Order::query()->count());
    }

    public function testTheWalletRenewsItWithThePackageAndARequestMadeAgainIsItsOrder(): void
    {
        $response = $this->renewing(['gb' => 50, 'days' => 30, 'method_id' => $this->wallet->id]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $checkout = $this->decode($response)['checkout'];
        self::assertSame(['settled', 'renewal', 'fulfilled', null, Traffic::bytesOfGb(50), 30, 2], [
            $checkout['outcome'],
            $checkout['order']['type'],
            $checkout['order']['status'],
            $checkout['order']['plan'],
            $checkout['order']['traffic_bytes'],
            $checkout['order']['days'],
            $checkout['order']['devices'],
        ]);
        self::assertSame([null, false, true, Traffic::bytesOfGb(100)], [$checkout['subscription']['plan'], $checkout['subscription']['renewable'], $checkout['subscription']['picks_plan'], $checkout['subscription']['traffic']['limit_bytes']], 'renewed, and still without a plan');
        $order = Order::query()->sole();
        self::assertSame([OrderType::Renewal, OrderStatus::Fulfilled, null, RenewalMethod::Extend, '170000.00'], [$order->type, $order->status, $order->plan_id, $order->renewal_method, $order->amount]);
        self::assertSame([null, '2026-12-11 12:00:00'], [$this->built->refresh()->plan_id, $this->built->expires_at?->toDateTimeString()]);
        self::assertSame('830000.00', $this->ali->balance());

        $again = $this->renewing(['gb' => 50, 'days' => 30, 'method_id' => $this->wallet->id]);
        $other = $this->renewing(['gb' => 60, 'days' => 30, 'method_id' => $this->wallet->id]);

        self::assertSame($order->id, $this->decode($again)['checkout']['order']['id'], 'made again with its key: its order');
        $this->assertRefusedUnder('idempotency_key', RequestKeyReusedException::MESSAGE, $other, message: 'its key, but another package');
        self::assertSame([1, '830000.00'], [Order::query()->count(), $this->ali->balance()], 'paid once');
    }

    public function testADiscountCodeIsCheckedAgainstThePackageAndTakenOffItsRenewal(): void
    {
        $this->discountCode('NOWRUZ', 10);
        $this->discountCode('PLANS', 50, ['plan_ids' => [$this->plan()->id]]);
        $check = fn(string $code): ResponseInterface => $this->postJson($this->storeApi($this->website, '/discount-codes/check'), ['code' => $code, 'for' => 'renewal', 'subscription_id' => $this->built->id, 'gb' => 50, 'days' => 30]);

        $quote = $this->decode($check('nowruz'))['quote'];
        $planOnly = $check('PLANS');

        self::assertSame(['170000.00', '153000.00'], [$quote['base'], $quote['amount']]);
        self::assertSame(422, $planOnly->getStatusCode(), 'a code held to plans never reaches a package');
        self::assertArrayHasKey('code', $this->decode($planOnly)['errors']);

        $renewed = $this->renewing(['gb' => 50, 'days' => 30, 'method_id' => $this->wallet->id, 'discount_code' => 'NOWRUZ']);
        self::assertSame(200, $renewed->getStatusCode(), (string) $renewed->getBody());
        self::assertSame(['153000.00', '17000.00'], [Order::query()->sole()->amount, Order::query()->sole()->discount_amount]);
    }

    /** @return array{plans: list<array<string, mixed>>, custom_services: bool} */
    private function choices(Subscription $subscription): array
    {
        $response = $this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}/renewal-plans"));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response);
    }

    /** @return array{mixed, mixed, mixed, mixed} ali_1's plan, and whether it is renewable, picks and renews by itself. */
    private function judged(): array
    {
        $row = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$this->built->id}")))['subscription'];

        return [$row['plan'], $row['renewable'], $row['picks_plan'], $row['auto_renew']['offered']];
    }

    /** @param array<string, int|string> $query */
    private function preview(array $query, ?Subscription $subscription = null): ResponseInterface
    {
        $id = ($subscription ?? $this->built)->id;

        return $this->get($this->storeApi($this->website, "/subscriptions/{$id}/renewal" . ($query === [] ? '' : '?' . http_build_query($query))));
    }

    /**
     * The preview's refusal: a 422, by field.
     *
     * @param array<string, int|string> $query
     * @return array<string, list<string>>
     */
    private function refused(array $query, ?Subscription $subscription = null): array
    {
        $response = $this->preview($query, $subscription);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['errors'];
    }

    /** @param array<string, mixed> $body */
    private function renewing(array $body, string $key = 'renew-1'): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$this->built->id}/renewal"), $body, ['Idempotency-Key' => $key]);
    }
}
