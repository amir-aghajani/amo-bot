<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerCheckout;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * The renewal of a service without a plan — one added by its link — on the website: the plans it may be renewed on (those
 * on sale on its server, at the customer's prices), the plan picked for the preview, the payment and a discount code, and
 * the renewal making it the service's plan; a request made again is answered by its order on the plan it picked, and a
 * page drawn before that renewal renews it on no other plan.
 */
final class StoreClaimedRenewalTest extends HttpTestCase
{
    private Website $website;

    private User $ali;

    private Server $server;

    private Plan $monthly;

    private Plan $quarterly;

    private Plan $parisOnly;

    private Subscription $claimed;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->server = $this->sellingServer('Berlin');
        $this->monthly = $this->plan([], $this->server);
        $this->quarterly = $this->plan(['name' => 'سه‌ماهه', 'price' => '300000.00', 'duration_hours' => 2160, 'traffic_gb' => 100, 'ip_limit' => 2], $this->server);
        $this->parisOnly = $this->plan(['name' => 'پاریس'], $this->sellingServer('Paris'));
        $this->plan(['name' => 'خاموش', 'is_active' => false], $this->server);
        $this->ali = $this->wallet($this->customer(['username' => 'ali']), '1000000.00');
        $this->claimed = $this->mirrored($this->claimedSubscription($this->ali, $this->server, 'friend_7', ['claimed_at' => now()->subDay()]));
        $this->bearer($this->customerSession($this->ali));
    }

    public function testItsPlansAreThoseOnSaleOnItsServerAtTheCustomersRenewalPrices(): void
    {
        $plans = $this->plans($this->claimed);

        self::assertSame([$this->monthly->id, $this->quarterly->id], array_column($plans, 'id'), "on sale on Berlin: not Paris's, not one switched off");
        self::assertSame([
            'id' => $this->monthly->id,
            'name' => 'یک‌ماهه',
            'description' => null,
            'checkout_note' => null,
            'price' => '120000.00',
            'renewal_price' => '120000.00',
            'discounts' => [],
            'traffic_gb' => 30,
            'duration_days' => 30,
            'duration_hours' => 720,
            'devices' => 1,
            'category_id' => null,
        ], $plans[0]);

        $this->sale('حراج', 10);
        $quarterly = $this->plans($this->claimed)[1];
        self::assertSame(['270000.00', [['source' => 'sale', 'label' => 'حراج ۱۰٪', 'amount' => '30000.00', 'percent' => 10]]], [$quarterly['renewal_price'], $quarterly['discounts']]);
        self::assertSame('270000.00', $this->decode($this->preview($this->claimed, $this->quarterly->id))['renewal']['price'], 'what the preview says');
    }

    public function testItsPlansComeByCategoryAsTheCatalogueGroupsThem(): void
    {
        $long = $this->category(['name' => 'بلندمدت']);
        $off = $this->category(['name' => 'خاموش', 'is_active' => false]);
        $this->quarterly->forceFill(['category_id' => $long->id])->save();
        $this->monthly->forceFill(['category_id' => $off->id])->save();
        $loose = $this->plan(['name' => 'بی دسته'], $this->server);

        $plans = $this->plans($this->claimed);

        self::assertSame([[$this->quarterly->id, $long->id], [$this->monthly->id, null], [$loose->id, null]], array_map(static fn(array $plan): array => [$plan['id'], $plan['category_id']], $plans), "the category's first, then those of none or of one switched off, as `GET /plans` groups them");
    }

    public function testAServiceWithItsPlanListsItAloneAndOneThatMayNotBeRenewedNone(): void
    {
        $own = $this->subscription($this->ali, $this->quarterly, $this->server, 'ali_1');
        $test = $this->subscription($this->ali, $this->monthly, $this->server, 'test_1', ['plan_id' => null, 'is_test' => true]);
        $off = $this->claimedSubscription($this->ali, $this->server, 'friend_8', ['status' => SubscriptionStatus::Disabled]);

        self::assertSame([$this->quarterly->id], array_column($this->plans($own), 'id'));
        self::assertSame([], $this->plans($test), 'a free test is never renewed');
        self::assertSame([], $this->plans($off), "support's switch: a renewal would turn it back on");

        $rows = array_column($this->decode($this->get($this->storeApi($this->website, '/subscriptions')))['subscriptions'], null, 'name');
        $judged = static fn(array $row): array => [$row['renewable'], $row['picks_plan']];
        self::assertSame([true, false], $judged($rows['ali_1']), 'renewed on its own plan');
        self::assertSame([false, true], $judged($rows['friend_7']), 'renewed on a plan picked: never `renewable`, which a website that knows nothing of picking would renew on no plan');
        self::assertSame([false, false], $judged($rows['test_1']), 'a free test');
        self::assertSame([false, false], $judged($rows['friend_8']), 'switched off');
    }

    public function testThePreviewAsksForAPlanAmongItsChoices(): void
    {
        $missing = $this->preview($this->claimed);
        $elsewhere = $this->preview($this->claimed, $this->parisOnly->id);

        $this->assertRefusedUnder('plan_id', CustomerCheckout::PICK_A_PLAN, $missing);
        $this->assertRefusedUnder('plan_id', CustomerCheckout::PLAN_NOT_OFFERED, $elsewhere, message: 'not sold on its server');

        $picked = $this->decode($this->preview($this->claimed, $this->quarterly->id))['renewal'];
        self::assertSame([$this->quarterly->id, '300000.00'], [$picked['plan']['id'], $picked['price']]);

        $own = $this->mirrored($this->subscription($this->ali, $this->monthly, $this->server, 'ali_1'));
        $another = $this->preview($own, $this->quarterly->id);
        $this->assertRefusedUnder('plan_id', CustomerCheckout::PLAN_NOT_OFFERED, $another, message: 'its own plan: another one named is refused, never quietly swapped for it');
    }

    public function testRenewingOnThePickedPlanMakesItTheServicesPlan(): void
    {
        $unpicked = $this->renewing($this->walletMethod(), null, 'renew-0');
        $this->assertRefusedUnder('plan_id', CustomerCheckout::PICK_A_PLAN, $unpicked);
        self::assertSame(0, Order::query()->count());

        $response = $this->renewing($this->walletMethod(), $this->quarterly->id);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $checkout = $this->decode($response)['checkout'];
        self::assertSame(['settled', 'renewal', 'fulfilled'], [$checkout['outcome'], $checkout['order']['type'], $checkout['order']['status']]);
        self::assertSame($this->quarterly->id, Order::query()->sole()->plan_id);
        self::assertSame(['id' => $this->quarterly->id, 'name' => 'سه‌ماهه'], $checkout['subscription']['plan']);
        self::assertSame([true, false], [$checkout['subscription']['renewable'], $checkout['subscription']['picks_plan']], 'renewed on its own plan from now on, nothing to pick');
        $this->claimed->refresh();
        self::assertSame($this->quarterly->id, $this->claimed->plan_id, 'the plan paid for is its plan now');
        self::assertNotNull($this->claimed->claimed_at, 'still known as added by its link');
        self::assertSame('700000.00', $this->ali->balance());

        self::assertSame($this->quarterly->id, $this->decode($this->preview($this->claimed))['renewal']['plan']['id'], 'its next renewal is on it, nothing to pick');
        self::assertSame([$this->quarterly->id], array_column($this->plans($this->claimed), 'id'));
    }

    public function testARenewalMadeAgainIsAnsweredByItsOrderOnThePlanItPicked(): void
    {
        $card = $this->cardMethod();
        $first = $this->decode($this->renewing($card, $this->quarterly->id, 'renew-once'))['checkout'];
        self::assertSame('transfer', $first['outcome']);
        // The receipt goes to support: the renewal is under way.
        $this->receipt(Order::query()->sole()->payments()->sole());

        $again = $this->renewing($card, $this->quarterly->id, 'renew-once');
        $otherPlan = $this->renewing($card, $this->monthly->id, 'renew-once');

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        $answer = $this->decode($again)['checkout'];
        self::assertSame(['transfer', $first['order']['id'], 'awaiting_review'], [$answer['outcome'], $answer['order']['id'], $answer['order']['payments'][0]['status']], 'its own order: the transfer whose receipt is with support');
        $this->assertRefusedUnder('idempotency_key', RequestKeyReusedException::MESSAGE, $otherPlan, message: 'its key, but another plan: no request made again');
        self::assertSame(1, Order::query()->count());
    }

    public function testATabDrawnBeforeItsFirstRenewalCannotRenewItAgainOnAnotherPlan(): void
    {
        // Two tabs list its choices. The first renews it from the wallet on the quarterly plan, which is its plan now.
        $first = $this->renewing($this->walletMethod(), $this->quarterly->id, 'tab-1');
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $order = $this->decode($first)['checkout']['order'];
        self::assertSame(['fulfilled', '700000.00', $this->quarterly->id], [$order['status'], $this->ali->balance(), $this->claimed->refresh()->plan_id]);

        // The second still offers the monthly plan.
        $this->discountCode('NOWRUZ', 20);
        $preview = $this->preview($this->claimed, $this->monthly->id);
        $check = $this->postJson($this->storeApi($this->website, '/discount-codes/check'), ['code' => 'NOWRUZ', 'for' => 'renewal', 'subscription_id' => $this->claimed->id, 'plan_id' => $this->monthly->id]);
        $second = $this->renewing($this->walletMethod(), $this->monthly->id, 'tab-2');

        foreach (['the preview' => $preview, 'a discount code' => $check, 'the renewal' => $second] as $case => $response) {
            $this->assertRefusedUnder('plan_id', CustomerCheckout::PLAN_NOT_OFFERED, $response, message: "{$case}: a plan it is no longer renewed on");
        }
        self::assertSame([1, '700000.00'], [Order::query()->count(), $this->ali->balance()], 'never renewed — and paid for — on the plan the page showed but no longer holds');

        // The first tab's request made again: its order, on the plan it named — or, naming none, the service's own now.
        $again = $this->renewing($this->walletMethod(), $this->quarterly->id, 'tab-1');
        $unnamed = $this->renewing($this->walletMethod(), null, 'tab-1');
        $otherPlan = $this->renewing($this->walletMethod(), $this->monthly->id, 'tab-1');

        foreach (['the plan it named' => $again, 'no plan named' => $unnamed] as $case => $response) {
            self::assertSame(200, $response->getStatusCode(), "{$case}: {$response->getBody()}");
            $answer = $this->decode($response)['checkout'];
            self::assertSame(['settled', $order['id'], 'fulfilled'], [$answer['outcome'], $answer['order']['id'], $answer['order']['status']], "{$case}: the renewal that key made, delivered");
        }
        $this->assertRefusedUnder('idempotency_key', RequestKeyReusedException::MESSAGE, $otherPlan, message: 'its key, but another plan: no request made again');
        self::assertSame([1, '700000.00'], [Order::query()->count(), $this->ali->balance()], 'one renewal, paid once');
    }

    public function testWithoutAPlanOnSaleOnItsServerItCannotBeRenewed(): void
    {
        $stranded = $this->claimedSubscription($this->ali, $this->sellingServer('Tokyo'), 'friend_9');

        $rows = array_column($this->decode($this->get($this->storeApi($this->website, '/subscriptions')))['subscriptions'], null, 'name');
        self::assertSame([null, false, false, '2026-10-12T12:00:00+00:00'], [$rows['friend_9']['plan'], $rows['friend_9']['renewable'], $rows['friend_9']['picks_plan'], $rows['friend_9']['claimed_at']], 'nothing to pick on Tokyo');
        self::assertSame([null, false, true, '2026-10-11T12:00:00+00:00'], [$rows['friend_7']['plan'], $rows['friend_7']['renewable'], $rows['friend_7']['picks_plan'], $rows['friend_7']['claimed_at']], 'plans to pick on Berlin');
        self::assertSame([], $this->plans($stranded));

        $this->assertRefusedUnder('status', CustomerCheckout::NOT_RENEWABLE, $this->preview($stranded, $this->monthly->id));
    }

    public function testADiscountCodeIsCheckedOnThePlanPicked(): void
    {
        $this->discountCode('NOWRUZ', 20);
        $check = fn(array $body): ResponseInterface => $this->postJson($this->storeApi($this->website, '/discount-codes/check'), ['code' => 'NOWRUZ', 'for' => 'renewal', 'subscription_id' => $this->claimed->id] + $body);

        $quote = $this->decode($check(['plan_id' => $this->quarterly->id]))['quote'];
        $unpicked = $check([]);

        self::assertSame(['300000.00', '240000.00'], [$quote['base'], $quote['amount']]);
        $this->assertRefusedUnder('plan_id', CustomerCheckout::PICK_A_PLAN, $unpicked);
    }

    /** @return list<array<string, mixed>> */
    private function plans(Subscription $subscription): array
    {
        $response = $this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}/renewal-plans"));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['plans'];
    }

    private function preview(Subscription $subscription, ?int $planId = null): ResponseInterface
    {
        $query = $planId === null ? '' : "?plan_id={$planId}";

        return $this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}/renewal{$query}"));
    }

    private function renewing(PaymentMethod $method, ?int $planId, string $key = 'renew-1'): ResponseInterface
    {
        $body = ['method_id' => $method->id] + ($planId === null ? [] : ['plan_id' => $planId]);

        return $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$this->claimed->id}/renewal"), $body, ['Idempotency-Key' => $key]);
    }
}
