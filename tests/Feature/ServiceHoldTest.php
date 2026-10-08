<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\Lease;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\GrantStatus;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\Grants;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * A change of a service on its panel holds the service (a lease on its row) as long as a call to the panel may take —
 * renewed before every call, so a change at work keeps it however slow its panel. One that lost it all the same once the
 * panel took the change (it stalled past its time, and another change took the service) is done: what the panel answered
 * is written by the row's key, and nothing gives, renews or moves the service a second time. One that lost it before
 * touching the panel did nothing: busy, and tried again.
 */
final class ServiceHoldTest extends DatabaseTestCase
{
    private User $ali;
    private Server $server;
    private Plan $plan;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->fakePanel();
        $this->telegram();
        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali']);
        $this->server = $this->fakeServer();
        $this->plan = $this->plan();
        $this->subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(10)]);
        FakeProvider::mirror($this->subscription);
    }

    public function testAGrantWhoseHoldRanOutAfterThePanelTookItIsGivenOnce(): void
    {
        $logs = $this->logs();
        $grants = $this->service(Grants::class);
        $part = $grants->start(['days' => '3', 'traffic_gb' => '0', 'audience' => 'server', 'server_id' => $this->server->id], $this->panelActor())->parts()->sole();
        $this->takenOverOn('updateClient');

        $grants->process($part, microtime(true) + 5);
        $this->letGo();
        $grants->process($part->refresh(), microtime(true) + 5);

        self::assertCount(1, FakeProvider::$updated, 'the panel took it once: the service is not given again');
        self::assertSame('2026-10-03', FakeProvider::lastUpdate('ali_1')->expiry->deadline()?->format('Y-m-d'));
        self::assertSame('2026-10-03', $this->subscription->refresh()->expires_at?->format('Y-m-d'), 'what the panel answered is on the row');
        self::assertSame([GrantStatus::Done, 1, 0, 0], [$part->refresh()->status, $part->granted, $part->skipped, $part->failed]);
        self::assertTrue($logs->hasWarningThatContains('outlasted its hold'), 'two changes of the service overlapped: logged');
    }

    public function testARenewalWhoseHoldRanOutAfterThePanelTookItIsDeliveredOnce(): void
    {
        $this->takenOverOn('updateClient');

        $order = $this->payRenewal();

        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, (string) $order->notes);
        self::assertCount(1, FakeProvider::$updated);
        self::assertSame('2026-10-30', FakeProvider::lastUpdate('ali_1')->expiry->deadline()?->format('Y-m-d'), 'the plan\'s 30 days, once');
        self::assertSame('2026-10-30', $this->subscription->refresh()->expires_at?->format('Y-m-d'));
        self::assertSame($this->plan->id, $this->subscription->plan_id);
    }

    public function testAMoveWhoseHoldRanOutAfterBothPanelsChangedPointsTheServiceAtItsNewClient(): void
    {
        $target = $this->sellingServer('هلند');
        $this->planEntry($this->plan, $target);
        $this->takenOverOn('deleteClient');

        $this->service(ProvisioningService::class)->move($this->subscription, $target, false);

        $this->subscription->refresh();
        self::assertSame([$target->id, 'ali_1'], [$this->subscription->server_id, $this->subscription->remote_name], 'never the client deleted on the old panel');
        self::assertSame([['server' => $this->server->id, 'name' => 'ali_1']], FakeProvider::$deleted);
        self::assertSame([$target->id], array_column(FakeProvider::$created, 'server'));
    }

    public function testARotationWhoseHoldRanOutAfterThePanelTookItKeepsTheNewLink(): void
    {
        $this->takenOverOn('rotateClientCredentials');

        $this->service(ProvisioningService::class)->rotateLink($this->subscription);

        self::assertSame(['ali_1'], FakeProvider::$rotated, 'rotated once: the link handed out is the one that works');
        self::assertSame(FakeProvider::$clients[$this->server->id]['ali_1']->subscriptionUrl, $this->subscription->refresh()->subscription_url);
    }

    public function testAChangeKeepsItsHoldThroughCallsSlowerThanOneHold(): void
    {
        $hold = ProvisioningService::holdSeconds($this->server);
        $others = [];
        // Each call to the panel takes most of a hold: two of them would outlast one.
        FakeProvider::$onCall = function (int $server, string $call) use ($hold, &$others): void {
            Carbon::setTestNow(now()->addSeconds($hold - 10));
            if ($call === 'updateClient') {
                $others[] = Lease::take(Subscription::query()->findOrFail($this->subscription->id), 60);
            }
        };

        $order = $this->payRenewal();

        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, (string) $order->notes);
        self::assertSame([null], $others, 'renewed before the second call: no other change took the service meanwhile');
    }

    public function testAHoldIsAsLongAsACallToTheSlowestPanelAskedMayTake(): void
    {
        $slow = $this->fakeServer('هلند', ['meta' => ['timeout' => 90]]);

        self::assertGreaterThan(ProvisioningService::holdSeconds($this->server), ProvisioningService::holdSeconds($slow), 'a panel given longer to answer is held longer');
        self::assertSame(ProvisioningService::holdSeconds($slow), ProvisioningService::holdSeconds($this->server, $slow), 'a move: the slower of its two panels');

        $held = [];
        FakeProvider::$onCall = function (int $server, string $call) use (&$held): void {
            $held[] = $this->subscription->newModelQuery()->whereKey($this->subscription->id)->value(Lease::UNTIL);
        };
        $this->service(ProvisioningService::class)->grant($this->subscription, 3, 0);

        self::assertSame([now()->addSeconds(ProvisioningService::holdSeconds($this->server))->toDateTimeString()], array_map(static fn(mixed $until): string => Carbon::parse((string) $until)->toDateTimeString(), $held));
    }

    public function testAHoldLostBeforeTheChangeTouchedThePanelChangesNothingAndTheRetryRenewsOnce(): void
    {
        // Taken over while the renewal reads the panel: the change has not touched it yet.
        $this->takenOverOn('findClient');

        $order = $this->payRenewal();

        self::assertSame([OrderStatus::Failed, (new ServiceBusyException())->getMessage()], [$order->refresh()->status, $order->notes]);
        self::assertSame([], FakeProvider::$updated, 'the panel was not asked to renew it');

        $this->letGo();
        FakeProvider::$onCall = null;
        $this->service(OrderActions::class)->retry($order->load('payments'));

        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        self::assertSame('2026-10-30', FakeProvider::lastUpdate('ali_1')->expiry->deadline()?->format('Y-m-d'), 'renewed once');
    }

    /** As the panel takes `$call`, another change takes the service over (its hold, a token of its own). */
    private function takenOverOn(string $call): void
    {
        $taken = false;
        FakeProvider::$onCall = function (int $server, string $made) use ($call, &$taken): void {
            if ($made === $call && !$taken) {
                $taken = true;
                Subscription::query()->whereKey($this->subscription->id)->update([Lease::TOKEN => str_repeat('x', 32), Lease::UNTIL => now()->addMinute()]);
            }
        };
    }

    /** The other change lets the service go. */
    private function letGo(): void
    {
        Subscription::query()->whereKey($this->subscription->id)->update(Lease::FREE);
    }

    /** Ali renews the service on its plan from his wallet: the order, delivered — or not — as it stands. */
    private function payRenewal(): Order
    {
        $this->service(WalletService::class)->credit($this->ali, $this->plan->price, 'test');
        $order = $this->service(OrderService::class)->createRenewal($this->ali, $this->subscription, $this->plan);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        return $order;
    }
}
