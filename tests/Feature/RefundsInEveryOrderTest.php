<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;

/**
 * A refund takes back what its order gave, as far as the service still has it: a service's renewals and extras,
 * refunded in every order, leave it exactly where it was before any of them — its deadline (or the term still waiting
 * for its first connection), the traffic left and its device limit —, whatever each renewal's method, a reset that
 * replaced more than it gave, a plan without a term or a quota that ended them, or extra days that started a new term.
 */
final class RefundsInEveryOrderTest extends DatabaseTestCase
{
    /** When the orders are refunded: three days after the service's state was taken. */
    private const REFUNDED_AT = '2026-10-13 12:00:00';

    private User $ali;
    private Server $server;
    private Plan $plan;
    private int $services = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['ip_limit' => 1], $this->server);
        $this->extraDevicesOnSale();
    }

    public function testDevicesAndTwoResetsOfTheTime(): void
    {
        $this->inEveryOrder(['expires_at' => now()->addDays(10)], function (Subscription $service): array {
            $devices = $this->paidExtra($service, ExtraKind::Devices, 2, '20000');
            Carbon::setTestNow('2026-10-10 13:00:00');
            $first = $this->renewBy($service, $this->plan, RenewalMethod::ResetTime);
            Carbon::setTestNow('2026-10-10 14:00:00');
            $more = $this->paidExtra($service, ExtraKind::Devices, 1, '30000');
            Carbon::setTestNow('2026-10-10 15:00:00');

            return [$devices, $first, $more, $this->renewBy($service, $this->plan, RenewalMethod::ResetTime)];
        });
    }

    public function testDevicesAndRenewalsOfAServiceWaitingForItsFirstConnection(): void
    {
        $two = $this->plan(['ip_limit' => 2], $this->server);
        $three = $this->plan(['ip_limit' => 3], $this->server);

        $this->inEveryOrder(['starts_at' => null, 'expires_at' => null], function (Subscription $service) use ($two, $three): array {
            $devices = $this->paidExtra($service, ExtraKind::Devices, 2, '20000');
            Carbon::setTestNow('2026-10-10 13:00:00');
            $first = $this->renewBy($service, $two, RenewalMethod::Extend);
            Carbon::setTestNow('2026-10-10 14:00:00');
            $more = $this->paidExtra($service, ExtraKind::Devices, 1, '30000');
            Carbon::setTestNow('2026-10-10 15:00:00');

            return [$devices, $first, $more, $this->renewBy($service, $three, RenewalMethod::ResetTime)];
        });
    }

    public function testAnExtensionExtraTrafficAndResetsOfTheTimeAndTheTraffic(): void
    {
        $long = $this->plan(['ip_limit' => 1, 'duration_hours' => 60 * 24, 'traffic_gb' => 50], $this->server);

        // Five days and 20 GB of its 30 left.
        $this->inEveryOrder(['expires_at' => now()->addDays(5), 'download_bytes' => 10 * Traffic::GIGABYTE], function (Subscription $service) use ($long): array {
            $extension = $this->renewBy($service, $this->plan, RenewalMethod::Extend);
            $traffic = $this->paidExtra($service, ExtraKind::Traffic, 10, '18000');
            Carbon::setTestNow('2026-10-11 12:00:00');
            $time = $this->renewBy($service, $long, RenewalMethod::ResetTime);

            return [$extension, $traffic, $time, $this->renewBy($service, $long, RenewalMethod::ResetTraffic)];
        });
    }

    public function testResetsThatReplacedMoreThanTheyGave(): void
    {
        $week = $this->plan(['ip_limit' => 1, 'duration_hours' => 7 * 24, 'traffic_gb' => 10], $this->server);

        // 25 days and 40 GB of its 50 left: a week of 10 GB each time replaces more than it gives.
        $this->inEveryOrder(['expires_at' => now()->addDays(25), 'traffic_limit_bytes' => 50 * Traffic::GIGABYTE, 'download_bytes' => 10 * Traffic::GIGABYTE], function (Subscription $service) use ($week): array {
            $traffic = $this->paidExtra($service, ExtraKind::Traffic, 10, '18000');
            Carbon::setTestNow('2026-10-10 13:00:00');
            $time = $this->renewBy($service, $week, RenewalMethod::ResetTime);
            self::assertSame(7 * 24 - (25 * 24 - 1), $time->refresh()->given_hours, 'a week in place of the 599 hours left');
            $days = $this->paidExtra($service, ExtraKind::Time, 3, '3000');
            Carbon::setTestNow('2026-10-10 15:00:00');
            $count = $this->renewBy($service, $week, RenewalMethod::ResetTraffic);
            self::assertSame(-50 * Traffic::GIGABYTE, $count->refresh()->given_bytes, '10 GB in place of the 60 left');

            return [$traffic, $time, $days, $count];
        });
    }

    public function testATermStillWaitingReplacedByAShorterOne(): void
    {
        $week = $this->plan(['ip_limit' => 1, 'duration_hours' => 7 * 24], $this->server);

        // 25 days waiting for the first connection.
        $this->inEveryOrder(['starts_at' => null, 'expires_at' => null, 'duration_hours' => 25 * 24], function (Subscription $service) use ($week): array {
            $time = $this->renewBy($service, $week, RenewalMethod::ResetTime);
            self::assertSame(7 * 24 - 25 * 24, $time->refresh()->given_hours);
            $days = $this->paidExtra($service, ExtraKind::Time, 3, '3000');

            return [$time, $days, $this->renewBy($service, $this->plan, RenewalMethod::Extend)];
        });
    }

    public function testARenewalOntoAPlanWithoutATermOrAQuotaAndOneBackOnto(): void
    {
        $endless = $this->plan(['ip_limit' => 1, 'duration_hours' => 0, 'traffic_gb' => 0], $this->server);

        // 25 days and 40 GB of its 50 left; 10 GB bought, then a plan that never ends and has no quota — and, a day later,
        // back on a 30-day, 30 GB plan, with 10 GB bought on top of it.
        $this->inEveryOrder(['expires_at' => now()->addDays(25), 'traffic_limit_bytes' => 50 * Traffic::GIGABYTE, 'download_bytes' => 10 * Traffic::GIGABYTE], function (Subscription $service) use ($endless): array {
            $before = $this->paidExtra($service, ExtraKind::Traffic, 10, '18000');
            $never = $this->renewBy($service, $endless, RenewalMethod::Extend);
            self::assertSame([0, 0, null], [$service->duration_hours, $service->traffic_limit_bytes, $service->expires_at], 'never ends, unlimited');
            self::assertSame([720, '2026-11-04 12:00:00', 50 * Traffic::GIGABYTE], [$never->refresh()->term_was, $never->expires_was?->format('Y-m-d H:i:s'), $never->quota_was], 'what it replaced, kept');
            Carbon::setTestNow('2026-10-11 12:00:00');
            $back = $this->renewBy($service, $this->plan, RenewalMethod::Extend);

            return [$before, $never, $back, $this->paidExtra($service, ExtraKind::Traffic, 10, '18000')];
        });

        // The same with days bought before and after.
        $this->inEveryOrder(['expires_at' => now()->addDays(25)], function (Subscription $service) use ($endless): array {
            $before = $this->paidExtra($service, ExtraKind::Time, 3, '3000');
            $never = $this->renewBy($service, $endless, RenewalMethod::Extend);
            Carbon::setTestNow('2026-10-11 12:00:00');
            $back = $this->renewBy($service, $this->plan, RenewalMethod::Extend);

            return [$before, $never, $back, $this->paidExtra($service, ExtraKind::Time, 7, '7000')];
        });

        // A term still waiting for the first connection: a plan that never ends (its 30 GB on top), then a 30-day one
        // without a quota.
        $this->inEveryOrder(['starts_at' => null, 'expires_at' => null], function (Subscription $service): array {
            $never = $this->renewBy($service, $this->plan(['ip_limit' => 1, 'duration_hours' => 0], $this->server), RenewalMethod::Extend);
            self::assertSame([720, null, null], [$never->refresh()->term_was, $never->expires_was, $never->quota_was]);

            return [$never, $this->renewBy($service, $this->plan(['ip_limit' => 1, 'traffic_gb' => 0], $this->server), RenewalMethod::ResetTime)];
        });
    }

    public function testExtraDaysThatStartedANewTermKeepItFromAnEarlierRenewalsRefund(): void
    {
        // Two days left, renewed for thirty; its time ran out on 11-11, and seven days bought the next day start a new
        // term from its next connection.
        $twoDaysLeft = ['expires_at' => Carbon::parse('2026-10-12 12:00:00')];
        $build = function (Subscription $service): array {
            $renewal = $this->renewBy($service, $this->plan, RenewalMethod::Extend);
            Carbon::setTestNow('2026-11-12 12:00:00');
            $days = $this->paidExtra($service, ExtraKind::Time, 7, '7000');
            self::assertSame([null, 7 * 24, true, 7 * 24], [$service->expires_at, $service->duration_hours, $days->refresh()->reset_term, $days->given_hours]);

            return [$renewal, $days];
        };

        $service = $this->newService($twoDaysLeft);
        [$renewal] = $build($service);
        $this->refund($renewal);
        self::assertSame([SubscriptionStatus::Active, null, 7 * 24], [$service->refresh()->status, $service->expires_at, $service->duration_hours], 'the renewal\'s refund takes nothing of the seven days paid for since');

        $this->inEveryOrder($twoDaysLeft, $build, refundedAt: '2026-11-13 12:00:00', ended: true);
    }

    /**
     * A service of `$state` with the orders `$build` makes on it, refunded in every order at REFUNDED_AT (or
     * `$refundedAt`): each ends it as it was before them — or, `$ended`, ended now with the traffic it had (a deadline
     * long gone ends at the refund).
     *
     * @param array<string, mixed> $state
     * @param \Closure(Subscription): list<Order> $build
     */
    private function inEveryOrder(array $state, \Closure $build, string $refundedAt = self::REFUNDED_AT, bool $ended = false): void
    {
        $permutations = null;
        $start = null;
        $run = 0;
        do {
            Carbon::setTestNow('2026-10-10 12:00:00');
            $service = $this->newService($state);
            $before = self::state($service);
            $orders = $build($service);
            $permutations ??= self::permutations(count($orders));
            $start ??= $ended ? [$refundedAt, null, $before[2], $before[3], $before[4], SubscriptionStatus::Expired->value] : $before;

            Carbon::setTestNow($refundedAt);
            foreach ($permutations[$run] as $i) {
                $this->refund($orders[$i]);
            }

            self::assertSame($start, self::state($service->refresh()), 'refunded in the order ' . implode(', ', $permutations[$run]));
        } while (++$run < count($permutations));
    }

    /** A new service of Ali's on the plan, its client on the fake panel, with `$state` for its columns. */
    /** @param array<string, mixed> $state */
    private function newService(array $state): Subscription
    {
        return $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_' . ++$this->services, $state));
    }

    /**
     * What a refund must leave as it was: the deadline, the term while it waits for the first connection, the traffic
     * left, the device limit and when it last went back to a package's, the status.
     *
     * @return list<mixed>
     */
    private static function state(Subscription $service): array
    {
        return [
            $service->expires_at?->format('Y-m-d H:i:s'),
            $service->awaitsFirstUse() ? $service->duration_hours : null,
            $service->remainingBytes(),
            $service->ip_limit,
            $service->ip_limit_reset_at?->format('Y-m-d H:i:s'),
            $service->status->value,
        ];
    }

    private function renewBy(Subscription $service, Plan $plan, RenewalMethod $method): Order
    {
        $this->service(WalletService::class)->credit($service->user, $plan->price, 'test');
        $order = $this->service(OrderService::class)->createRenewal($service->user, $service, $plan, $method);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, (string) $order->notes);
        $service->refresh();

        return $order;
    }

    private function refund(Order $order): void
    {
        $this->service(PaymentActions::class)->refund($order->payments()->firstOrFail(), $this->panelActor(), null);
    }

    /** @return list<list<int>> every order of 0 … `$n` - 1 */
    private static function permutations(int $n): array
    {
        if ($n === 1) {
            return [[0]];
        }
        $all = [];
        foreach (self::permutations($n - 1) as $shorter) {
            for ($at = 0; $at <= count($shorter); $at++) {
                $one = $shorter;
                array_splice($one, $at, 0, [$n - 1]);
                $all[] = $one;
            }
        }

        return $all;
    }
}
