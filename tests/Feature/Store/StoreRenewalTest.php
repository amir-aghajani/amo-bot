<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Http\ErrorHandler;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Exceptions\RenewalUnderWayException;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerCheckout;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * Renewals on the website: the preview is what the payment then makes, by the renewal method asked among those the
 * shop offers; a service that may not be renewed, may not be yet (its window says from when), or is being renewed, is
 * refused, and so is a plan named that is not its own or a method the shop does not offer; a repeated request is
 * answered by its order.
 */
final class StoreRenewalTest extends HttpTestCase
{
    private Website $website;

    private User $ali;

    private Server $server;

    private Plan $plan;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-19 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(on: $this->server);
        $this->ali = $this->wallet($this->customer(['username' => 'ali']), '500000.00');
        // 18 GB of its 30 used, 12 left; it ends in 30 days.
        $this->subscription = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['download_bytes' => Traffic::bytesOfGb(18)]));
        $this->bearer($this->customerSession($this->ali));
    }

    public function testThePreviewIsWhatThePaymentThenMakes(): void
    {
        $preview = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal")))['renewal'];

        self::assertSame(['id' => $this->plan->id, 'name' => 'یک‌ماهه', 'description' => null, 'checkout_note' => null, 'price' => '120000.00', 'traffic_gb' => 30, 'duration_days' => 30, 'duration_hours' => 720, 'devices' => 1], $preview['plan'], 'the plan as the catalogue says it');
        self::assertSame('120000.00', $preview['price']);
        self::assertSame([
            'term' => ['duration_days' => 60, 'duration_hours' => 1440, 'starts_at' => '2026-09-19T12:00:00+00:00', 'expires_at' => '2026-11-18T12:00:00+00:00', 'awaits_first_use' => false],
            'traffic' => ['limit_bytes' => Traffic::bytesOfGb(60), 'used_bytes' => Traffic::bytesOfGb(18), 'remaining_bytes' => Traffic::bytesOfGb(42)],
            'next_period' => ['ends_at' => '2026-10-19T12:00:00+00:00', 'bytes' => Traffic::bytesOfGb(30)],
            'next_devices' => null,
        ], $preview['after'], "the plan's 30 days on top of the deadline, its 30 GB on top of the quota — and the 12 GB left going when this period ends");
        self::assertSame(0, Order::query()->count(), 'nothing ordered for a preview');

        $response = $this->renewing($this->walletMethod());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $checkout = $this->decode($response)['checkout'];
        self::assertSame(['settled', 'renewal', 'fulfilled'], [$checkout['outcome'], $checkout['order']['type'], $checkout['order']['status']]);
        $renewed = $checkout['subscription'];
        self::assertSame($this->subscription->id, $renewed['id']);
        self::assertSame($preview['after'], ['term' => $renewed['term'], 'traffic' => $renewed['traffic'], 'next_period' => $renewed['next_period'], 'next_devices' => $renewed['next_devices']], 'what the preview said');
        self::assertSame('380000.00', $this->ali->balance());
    }

    public function testDevicesBoughtLastTillThePeriodInUseEndsAndThePreviewSaysFromWhenThePlansCome(): void
    {
        // Two devices bought on top of the plan's one; the shop carries unused traffic over, so no traffic waits.
        $this->subscription->forceFill(['ip_limit' => 3])->save();
        $this->mirrored($this->subscription);
        $this->botSettings('renewal', ['carry_traffic' => true]);

        $after = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal")))['renewal']['after'];
        self::assertSame([null, ['from' => '2026-10-19T12:00:00+00:00', 'devices' => 1]], [$after['next_period'], $after['next_devices']], "the plan's one device from the end of the period in use");

        $renewed = $this->decode($this->renewing($this->walletMethod()))['checkout']['subscription'];
        self::assertSame([3, $after['next_devices']], [$renewed['devices'], $renewed['next_devices']], 'the three until then, as the preview said');
    }

    public function testAnEndedServicesPreviewWaitsForItsNextConnectionAsTheRenewalDoes(): void
    {
        // Its 30 days ran out ten days ago.
        $this->subscription->forceFill(['status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(40), 'expires_at' => now()->subDays(10)])->save();
        $this->mirrored($this->subscription);

        $preview = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal")))['renewal'];
        self::assertSame(['duration_days' => 30, 'duration_hours' => 720, 'starts_at' => null, 'expires_at' => null, 'awaits_first_use' => true], $preview['after']['term'], 'a new term from its next connection: no start yet — not the old one');

        $renewed = $this->decode($this->renewing($this->walletMethod()))['checkout']['subscription'];
        self::assertSame($preview['after'], ['term' => $renewed['term'], 'traffic' => $renewed['traffic'], 'next_period' => $renewed['next_period'], 'next_devices' => $renewed['next_devices']], 'what the preview said');
    }

    public function testAServiceItsCustomerMayNotRenewNowIsRefused(): void
    {
        $this->subscription->forceFill(['status' => SubscriptionStatus::Disabled])->save();

        $preview = $this->get($this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal"));
        $renewal = $this->renewing($this->walletMethod());

        foreach ([$preview, $renewal] as $response) {
            $this->assertRefusedUnder('status', CustomerCheckout::NOT_RENEWABLE, $response);
        }
        self::assertSame(0, Order::query()->count());
        self::assertSame('500000.00', $this->ali->balance());
    }

    public function testARenewalUnderWayIsRefusedButOneMadeAgainIsAnsweredByItsOrder(): void
    {
        $card = $this->cardMethod();
        $first = $this->decode($this->renewing($card, 'renew-once'))['checkout'];
        self::assertSame('transfer', $first['outcome']);
        // The receipt goes to support: the renewal is under way.
        $this->receipt(Order::query()->sole()->payments()->sole());
        $underWay = (new RenewalUnderWayException($this->subscription))->getMessage();

        $preview = $this->get($this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal"));
        $another = $this->renewing($this->walletMethod(), 'renew-twice');
        $otherWay = $this->renewing($this->walletMethod(), 'renew-once');
        $again = $this->renewing($card, 'renew-once');

        $this->assertRefusedUnder('status', $underWay, $preview);
        self::assertSame(['plans' => [], 'custom_services' => false], $this->renewalPlans(), 'nothing to renew it on meanwhile');
        $this->assertRefusedUnder('status', $underWay, $another, message: 'never renewed — and paid for — twice');
        $this->assertRefusedUnder('idempotency_key', RequestKeyReusedException::OTHER_METHOD, $otherWay, message: 'its key, but another way to pay: no request made again');
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        $answer = $this->decode($again)['checkout'];
        self::assertSame(['transfer', $first['order']['id'], $first['transfer']], [$answer['outcome'], $answer['order']['id'], $answer['transfer']], 'its own order: the transfer whose receipt is with support');
        self::assertSame('awaiting_review', $answer['order']['payments'][0]['status']);
        self::assertSame([1, '500000.00'], [Order::query()->count(), $this->ali->balance()]);
    }

    public function testAPlanNamedForAServiceWithItsOwnMustBeThatPlan(): void
    {
        $quarterly = $this->plan(['name' => 'سه‌ماهه', 'price' => '300000.00', 'duration_hours' => 2160, 'traffic_gb' => 90], $this->server);
        $this->discountCode('NOWRUZ', 20);
        $path = $this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal");
        $check = fn(int $planId): ResponseInterface => $this->postJson($this->storeApi($this->website, '/discount-codes/check'), ['code' => 'NOWRUZ', 'for' => 'renewal', 'subscription_id' => $this->subscription->id, 'plan_id' => $planId]);

        $own = $this->get("{$path}?plan_id={$this->plan->id}");
        self::assertSame(200, $own->getStatusCode(), (string) $own->getBody());
        $preview = $this->decode($own)['renewal'];
        self::assertSame([$this->plan->id, '120000.00'], [$preview['plan']['id'], $preview['price']], 'its own plan named: as when none is');
        $quote = $this->decode($check($this->plan->id))['quote'];
        self::assertSame(['120000.00', '96000.00'], [$quote['base'], $quote['amount']], 'a code checked on its own plan named');

        foreach ([
            'the preview' => $this->get("{$path}?plan_id={$quarterly->id}"),
            'the preview, no such plan' => $this->get("{$path}?plan_id=999999"),
            'a discount code' => $check($quarterly->id),
            'the renewal' => $this->renewing($this->walletMethod(), 'renew-1', $quarterly->id),
        ] as $case => $response) {
            $this->assertRefusedUnder('plan_id', CustomerCheckout::PLAN_NOT_OFFERED, $response, message: "{$case}: another plan, though on sale on its server, is never renewed on — nor quietly swapped for its own");
        }
        self::assertSame([0, '500000.00'], [Order::query()->count(), $this->ali->balance()], 'nothing ordered, nothing paid');

        $renewed = $this->renewing($this->walletMethod(), 'renew-2', $this->plan->id);
        self::assertSame(200, $renewed->getStatusCode(), (string) $renewed->getBody());
        self::assertSame([$this->plan->id, '380000.00'], [Order::query()->sole()->plan_id, $this->ali->balance()], 'its own plan named: renewed on it');
    }

    public function testAServiceOfAnotherCustomerIsNotThere(): void
    {
        $theirs = $this->subscription($this->customer(['telegram_id' => 7272]), $this->plan, $this->server, 'sara_1');

        $preview = $this->get($this->storeApi($this->website, "/subscriptions/{$theirs->id}/renewal"));
        $renewal = $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$theirs->id}/renewal"), ['method_id' => $this->walletMethod()->id], ['Idempotency-Key' => 'theirs']);

        foreach ([$preview, $renewal] as $response) {
            self::assertSame([404, ErrorHandler::NOT_FOUND], [$response->getStatusCode(), $this->decode($response)['message']]);
        }
        self::assertSame(0, Order::query()->count());
    }

    public function testInAnAgentsShopTheirTrafficMustCoverTheRenewal(): void
    {
        $bot = $this->agentBot(traffic: 20);
        [$website, $token, $big] = CurrentBot::run($bot, function (): array {
            $customer = $this->customer(['telegram_id' => 8181]);
            $big = $this->subscription($customer, $this->plan(['name' => 'big', 'traffic_gb' => 50], $this->server), $this->server, 'sara_1');

            return [$this->website(), $this->customerSession($customer), $big];
        });
        $this->bearer($token);

        $response = $this->get($this->storeApi($website, "/subscriptions/{$big->id}/renewal"));

        $this->assertRefusedUnder('status', CustomerCheckout::NOT_RENEWABLE, $response, message: 'its 50 GB are more than their 20');
    }

    public function testThePreviewAndTheRenewalAreByTheMethodAskedAmongThoseOffered(): void
    {
        $this->botSettings('renewal', ['renew_extend' => true, 'renew_reset_traffic' => true]);
        $path = $this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal");
        $this->discountCode('NOWRUZ', 20);

        $first = $this->decode($this->get($path))['renewal'];
        self::assertSame(['extend', ['extend', 'reset_traffic'], Traffic::bytesOfGb(60)], [$first['method'], $first['methods'], $first['after']['traffic']['limit_bytes']], 'the first offered');

        $reset = $this->decode($this->get("{$path}?method=reset_traffic"))['renewal'];
        self::assertSame(['reset_traffic', '120000.00'], [$reset['method'], $reset['price']], 'the same price');
        self::assertSame(['limit_bytes' => Traffic::bytesOfGb(30), 'used_bytes' => 0, 'remaining_bytes' => Traffic::bytesOfGb(30)], $reset['after']['traffic'], 'counted afresh');
        self::assertSame([$first['after']['term']['expires_at'], null], [$reset['after']['term']['expires_at'], $reset['after']['next_period']], 'the days left carry; nothing queued');

        $check = fn(string $method): ResponseInterface => $this->postJson($this->storeApi($this->website, '/discount-codes/check'), ['code' => 'NOWRUZ', 'for' => 'renewal', 'subscription_id' => $this->subscription->id, 'method' => $method]);
        self::assertSame('96000.00', $this->decode($check('reset_traffic'))['quote']['amount']);
        foreach ([
            'the preview' => $this->get("{$path}?method=reset_time"),
            'the preview, no such method' => $this->unchecked()->get("{$path}?method=sideways"),
            'a discount code' => $check('reset_time'),
            'the renewal' => $this->renewing($this->walletMethod(), 'renew-1', by: 'reset_time'),
        ] as $case => $response) {
            $this->assertRefusedUnder('method', CustomerCheckout::METHOD_NOT_OFFERED, $response, message: "{$case}: a method the shop does not offer");
        }
        self::assertSame(0, Order::query()->count());

        $response = $this->renewing($this->walletMethod(), 'renew-2', by: 'reset_traffic');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $renewed = $this->decode($response)['checkout']['subscription'];
        self::assertSame($reset['after'], ['term' => $renewed['term'], 'traffic' => $renewed['traffic'], 'next_period' => $renewed['next_period'], 'next_devices' => $renewed['next_devices']], 'what the preview by that method said');
        self::assertSame(RenewalMethod::ResetTraffic, Order::query()->sole()->renewal_method);
    }

    public function testARequestMadeAgainByAnotherMethodIsNoRequestMadeAgain(): void
    {
        $this->botSettings('renewal', ['renew_extend' => true, 'renew_reset_time' => true]);
        $card = $this->cardMethod();

        $first = $this->decode($this->renewing($card, 'renew-once', by: 'reset_time'))['checkout'];
        $other = $this->renewing($card, 'renew-once');
        $again = $this->renewing($card, 'renew-once', by: 'reset_time');

        $this->assertRefusedUnder('idempotency_key', (new RequestKeyReusedException())->getMessage(), $other, message: 'its key, but by an extension');
        self::assertSame($first['order']['id'], $this->decode($again)['checkout']['order']['id']);
        self::assertSame([1, RenewalMethod::ResetTime], [Order::query()->count(), Order::query()->sole()->renewal_method]);
    }

    public function testARenewalBeforeItsWindowIsRefusedSayingFromWhen(): void
    {
        // It ends in 30 days; the shop lets it be renewed from 7 days before.
        $this->botSettings('renewal', ['renew_window_days' => 7, 'renew_window_percent' => 20]);
        $refusal = sprintf(CustomerCheckout::NOT_YET, 'از ' . Persian::date(Carbon::parse('2026-10-12 12:00:00'), withTime: true) . ' یا وقتی ٪۲۰ از حجم یا کمتر مانده باشد');

        $service = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$this->subscription->id}")))['subscription'];
        self::assertSame([true, ['open' => false, 'opens_at' => '2026-10-12T12:00:00+00:00', 'days_before' => null, 'traffic_percent' => 20]], [$service['renewable'], $service['renewal_window']]);

        foreach ([
            'the preview' => $this->get($this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal")),
            'a discount code' => $this->postJson($this->storeApi($this->website, '/discount-codes/check'), ['code' => 'NOWRUZ', 'for' => 'renewal', 'subscription_id' => $this->subscription->id]),
            'the renewal' => $this->renewing($this->walletMethod()),
        ] as $case => $response) {
            $this->assertRefusedUnder('status', $refusal, $response, message: $case);
        }
        self::assertSame([0, '500000.00'], [Order::query()->count(), $this->ali->balance()]);
        self::assertSame(['plans' => [], 'custom_services' => false], $this->renewalPlans(), 'nothing to renew it on yet');

        // 6 of its 30 GB left: twenty percent.
        $this->subscription->forceFill(['download_bytes' => Traffic::bytesOfGb(24)])->save();
        self::assertSame(200, $this->get($this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal"))->getStatusCode());
        self::assertSame([$this->plan->id], array_column($this->renewalPlans()['plans'], 'id'), 'its own plan, once its window opens');
    }

    /** @return array<string, mixed> What `GET …/renewal-plans` answers for the service. */
    private function renewalPlans(): array
    {
        $response = $this->get($this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal-plans"));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response);
    }

    /** A renewal paid with the way to pay, on `$planId` when named, by the renewal method `$by` when named. */
    private function renewing(PaymentMethod $method, string $key = 'renew-1', ?int $planId = null, ?string $by = null): ResponseInterface
    {
        $body = ['method_id' => $method->id] + ($planId === null ? [] : ['plan_id' => $planId]) + ($by === null ? [] : ['method' => $by]);

        return $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal"), $body, ['Idempotency-Key' => $key]);
    }
}
