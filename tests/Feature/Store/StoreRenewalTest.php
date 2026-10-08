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
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * A service's renewal on the shop's website — the bot's «♻️ تمدید سرویس», through the same checkout: its preview (the
 * plan, its price, the service as the renewal would leave it — CustomerRenewal::preview(), which the bot's checkout shows
 * too) is what the payment then makes; a service its customer may not renew, or one being renewed, is refused in the
 * API's words; a renewal made again with its key is answered by the order it made, even once that one is under way.
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
        $this->subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['download_bytes' => Traffic::bytesOfGb(18)]);
        FakeProvider::mirror($this->subscription);
        $this->bearer($this->customerSession($this->ali));
    }

    public function testThePreviewIsWhatThePaymentThenMakes(): void
    {
        $preview = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal")))['renewal'];

        self::assertSame(['id' => $this->plan->id, 'name' => 'یک‌ماهه', 'price' => '120000.00', 'traffic_gb' => 30, 'duration_days' => 30], $preview['plan']);
        self::assertSame('120000.00', $preview['price']);
        self::assertSame([
            'term' => ['duration_days' => 60, 'starts_at' => '2026-09-19T12:00:00+00:00', 'expires_at' => '2026-11-18T12:00:00+00:00', 'awaits_first_use' => false],
            'traffic' => ['limit_bytes' => Traffic::bytesOfGb(60), 'used_bytes' => Traffic::bytesOfGb(18), 'remaining_bytes' => Traffic::bytesOfGb(42)],
            'next_period' => ['ends_at' => '2026-10-19T12:00:00+00:00', 'bytes' => Traffic::bytesOfGb(30)],
        ], $preview['after'], "the plan's 30 days on top of the deadline, its 30 GB on top of the quota — and the 12 GB left going when this period ends");
        self::assertSame(0, Order::query()->count(), 'nothing ordered for a preview');

        $response = $this->renewing($this->walletMethod());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $checkout = $this->decode($response)['checkout'];
        self::assertSame(['settled', 'renewal', 'fulfilled'], [$checkout['outcome'], $checkout['order']['type'], $checkout['order']['status']]);
        $renewed = $checkout['subscription'];
        self::assertSame($this->subscription->id, $renewed['id']);
        self::assertSame($preview['after'], ['term' => $renewed['term'], 'traffic' => $renewed['traffic'], 'next_period' => $renewed['next_period']], 'what the preview said');
        self::assertSame('380000.00', $this->ali->balance());
    }

    public function testAnEndedServicesPreviewWaitsForItsNextConnectionAsTheRenewalDoes(): void
    {
        // Its 30 days ran out ten days ago.
        $this->subscription->forceFill(['status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(40), 'expires_at' => now()->subDays(10)])->save();
        FakeProvider::mirror($this->subscription);

        $preview = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal")))['renewal'];
        self::assertSame(['duration_days' => 30, 'starts_at' => null, 'expires_at' => null, 'awaits_first_use' => true], $preview['after']['term'], 'a new term from its next connection: no start yet — not the old one');

        $renewed = $this->decode($this->renewing($this->walletMethod()))['checkout']['subscription'];
        self::assertSame($preview['after'], ['term' => $renewed['term'], 'traffic' => $renewed['traffic'], 'next_period' => $renewed['next_period']], 'what the preview said');
    }

    public function testAServiceItsCustomerMayNotRenewNowIsRefused(): void
    {
        $this->subscription->forceFill(['status' => SubscriptionStatus::Disabled])->save();

        $preview = $this->get($this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal"));
        $renewal = $this->renewing($this->walletMethod());

        foreach ([$preview, $renewal] as $response) {
            self::assertSame([422, [CustomerCheckout::NOT_RENEWABLE]], [$response->getStatusCode(), $this->decode($response)['errors']['status'] ?? null]);
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

        self::assertSame([422, [$underWay]], [$preview->getStatusCode(), $this->decode($preview)['errors']['status'] ?? null]);
        self::assertSame([422, [$underWay]], [$another->getStatusCode(), $this->decode($another)['errors']['status'] ?? null], 'never renewed — and paid for — twice');
        self::assertSame([422, [RequestKeyReusedException::OTHER_METHOD]], [$otherWay->getStatusCode(), $this->decode($otherWay)['errors']['idempotency_key'] ?? null], 'its key, but another way to pay: no request made again');
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        $answer = $this->decode($again)['checkout'];
        self::assertSame(['transfer', $first['order']['id'], $first['transfer']], [$answer['outcome'], $answer['order']['id'], $answer['transfer']], 'its own order: the transfer whose receipt is with support');
        self::assertSame('awaiting_review', $answer['order']['payments'][0]['status']);
        self::assertSame([1, '500000.00'], [Order::query()->count(), $this->ali->balance()]);
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

        self::assertSame([422, [CustomerCheckout::NOT_RENEWABLE]], [$response->getStatusCode(), $this->decode($response)['errors']['status'] ?? null], 'its 50 GB are more than their 20');
    }

    private function renewing(PaymentMethod $method, string $key = 'renew-1'): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$this->subscription->id}/renewal"), ['method_id' => $method->id], ['Idempotency-Key' => $key]);
    }
}
