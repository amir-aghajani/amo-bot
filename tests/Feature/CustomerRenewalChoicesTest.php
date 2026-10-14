<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\DTO\RenewalPreview;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\RenewalRefusal;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use App\Support\Traffic;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * Renewing a service without a plan — one the customer claimed by its link: the plans they may pick (those on sale on
 * its server now that give something), the preview on a picked plan (and none on another plan for a service with its
 * own), which services the renewal screens list and offer, and the renewal delivered on the picked plan making it the
 * service's own.
 */
final class CustomerRenewalChoicesTest extends DatabaseTestCase
{
    private Server $germany;
    private Server $france;
    private User $ali;
    private Plan $monthly;
    private Plan $quarterly;
    private Plan $franceOnly;
    private Plan $offSale;
    private Plan $givesNothing;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->germany = $this->sellingServer('آلمان');
        $this->france = $this->sellingServer('فرانسه');
        $this->ali = $this->customer(['username' => 'ali']);
        $this->monthly = $this->plan([], $this->germany);
        $this->offSale = $this->plan(['name' => 'قدیمی', 'is_active' => false], $this->germany);
        $this->quarterly = $this->plan(['name' => 'سه‌ماهه', 'price' => '300000.00', 'duration_hours' => 2160, 'traffic_gb' => 90, 'ip_limit' => 3], [$this->france, $this->germany]);
        $this->franceOnly = $this->plan(['name' => 'فرانسه'], $this->france);
        $this->givesNothing = $this->plan(['name' => 'هیچ', 'duration_hours' => 0, 'traffic_gb' => 0], $this->germany);
    }

    public function testAClaimedServicePicksAmongThePlansOnSaleOnItsServerThatGiveSomething(): void
    {
        $claimed = $this->claimed($this->germany, 'manual_1');

        self::assertSame([$this->monthly->id, $this->quarterly->id], $this->renewal()->choices($claimed)->modelKeys(), 'in the catalogue\'s order; not one off sale, sold elsewhere, or giving nothing');
        self::assertSame([$this->quarterly->id, $this->franceOnly->id], $this->renewal()->choices($this->claimed($this->france, 'manual_2'))->modelKeys());

        $ended = $this->claimed($this->germany, 'manual_3', ['status' => SubscriptionStatus::Expired, 'ended_at' => now()]);
        self::assertCount(2, $this->renewal()->choices($ended), 'an ended service too');
    }

    public function testAServerThatCannotTakeANewSaleStillRenewsWhatIsOnIt(): void
    {
        $claimed = $this->claimed($this->germany, 'manual_1');
        $sold = $this->subscription($this->ali, $this->monthly, $this->germany, 'sold_1');
        foreach ([
            'switched off for sales' => ['is_active' => false],
            'full' => ['is_active' => true, 'capacity' => 1],
        ] as $case => $state) {
            $this->germany->forceFill($state)->save();

            self::assertSame([$this->monthly->id, $this->quarterly->id], $this->renewal()->choices($claimed)->modelKeys(), "{$case}: a renewal makes no new client");
            self::assertSame([$claimed->id => true, $sold->id => false], $this->renewal()->pickable([$claimed, $sold]), "{$case}: the one without a plan picks one; the other renews on its own");
            self::assertSame($this->monthly->id, $this->planOf($this->renewal()->preview($claimed, $this->monthly->id)), $case);
            self::assertSame($this->monthly->id, $this->renewal()->planFor($sold)?->id, "{$case}: its own plan, as before");
        }
    }

    public function testNoChoicesForAServiceWithAPlanATestOrOneSwitchedOffOrGone(): void
    {
        foreach ([
            'its own plan' => $this->subscription($this->ali, $this->monthly, $this->germany, 'sold_1'),
            'a free test' => $this->claimed($this->germany, 'test_1', ['is_test' => true]),
            'switched off' => $this->claimed($this->germany, 'manual_1', ['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()]),
            'gone from its panel' => $this->claimed($this->germany, 'manual_2', ['status' => SubscriptionStatus::Deleted, 'ended_at' => now()]),
        ] as $case => $service) {
            self::assertFalse(CustomerRenewal::picksPlan($service), $case);
            self::assertSame([], $this->renewal()->choices($service)->all(), $case);
        }
        self::assertTrue(CustomerRenewal::picksPlan($this->claimed($this->germany, 'manual_3')));
    }

    public function testThePreviewOfAServiceWithoutAPlanIsOnAPickedOneAmongItsChoices(): void
    {
        $claimed = $this->claimed($this->germany, 'manual_1');
        self::assertNull($this->renewal()->preview($claimed), 'a plan is still to be picked');
        self::assertSame(RenewalRefusal::PickAPlan, $this->renewal()->refusal($claimed), 'and why: the builder is off');
        foreach ([
            'sold elsewhere' => $this->franceOnly->id,
            'off sale' => $this->offSale->id,
            'giving nothing' => $this->givesNothing->id,
            'no such plan' => 999_999,
        ] as $case => $planId) {
            self::assertNull($this->renewal()->preview($claimed, $planId), $case);
            self::assertSame(RenewalRefusal::PlanNotOffered, $this->renewal()->refusal($claimed, $planId), $case);
        }
        ['plans' => $plans, 'builds' => $builds] = $this->renewal()->picks($claimed);
        self::assertSame([[$this->monthly->id, $this->quarterly->id], false], [array_map(static fn(Plan $plan): int => $plan->id, $plans), $builds], 'what it may be renewed on now');

        $preview = $this->renewal()->preview($claimed, $this->quarterly->id);
        self::assertNotNull($preview);
        self::assertSame($this->quarterly->id, $this->planOf($preview));
        self::assertSame('300000.00', $preview->quote->amount());
        self::assertSame('2027-01-17 12:00:00', $preview->after->expires_at?->toDateTimeString(), 'its deadline (19 October) plus 90 days');
        self::assertSame(110 * Traffic::GIGABYTE, $preview->after->traffic_limit_bytes, 'the plan\'s 90 GB on its 20');
        self::assertNull($claimed->refresh()->plan_id, 'nothing saved');
    }

    public function testAServiceWithItsOwnPlanPreviewsOnItAndOnNoOtherPlanNamed(): void
    {
        $sold = $this->subscription($this->ali, $this->monthly, $this->germany, 'sold_1');

        self::assertSame($this->monthly->id, $this->planOf($this->renewal()->preview($sold)), 'none named: its own plan');
        self::assertSame($this->monthly->id, $this->planOf($this->renewal()->preview($sold, $this->monthly->id)), 'its own plan named');
        self::assertNull($this->renewal()->preview($sold, $this->quarterly->id), 'another plan named, though on sale on its server: no preview, never one quietly on its own plan');
        self::assertSame(RenewalRefusal::PlanNotOffered, $this->renewal()->refusal($sold, $this->quarterly->id));
        self::assertNull($this->renewal()->refusal($sold), 'its own plan: none');

        $this->service(OrderService::class)->createRenewal($this->ali, $sold, $this->monthly)->forceFill(['status' => OrderStatus::Paid])->save();
        self::assertSame([null, RenewalRefusal::UnderWay], [$this->renewal()->preview($sold), $this->renewal()->refusal($sold)], 'a renewal under way');
        self::assertSame(['plans' => [], 'builds' => false], $this->renewal()->picks($sold), 'nothing to renew it on meanwhile');

        $sold->forceFill(['status' => SubscriptionStatus::Disabled])->save();
        self::assertSame(RenewalRefusal::NotRenewable, $this->renewal()->refusal($sold), 'switched off: not at all, asked before the rest');

        // A page drawn before its first renewal still offers the choices: the plan picked since is the only one now.
        $claimed = $this->claimed($this->germany, 'manual_1');
        $claimed->forceFill(['plan_id' => $this->quarterly->id])->save();
        self::assertNull($this->renewal()->preview($claimed, $this->monthly->id), 'one of its old choices');
        self::assertSame($this->quarterly->id, $this->planOf($this->renewal()->preview($claimed, $this->quarterly->id)));
    }

    public function testWhetherAPageOfServicesPicksAPlanReadsThePlansOnSaleOnce(): void
    {
        $netherlands = $this->sellingServer('هلند');
        $bob = $this->customer(['telegram_id' => 7003, 'username' => 'bob']);
        $services = [
            'its own plan' => $this->subscription($this->ali, $this->monthly, $this->germany, 'sold_1'),
            'its own plan, switched off' => $this->subscription($this->ali, $this->monthly, $this->germany, 'sold_2', ['status' => SubscriptionStatus::Disabled]),
            'claimed in Germany' => $this->claimed($this->germany, 'manual_1'),
            'claimed in France' => $this->claimed($this->france, 'manual_2'),
            'claimed in France, ended' => $this->claimed($this->france, 'manual_3', ['status' => SubscriptionStatus::Expired]),
            'claimed where nothing is sold' => $this->claimed($netherlands, 'manual_4'),
            'a free test' => $this->claimed($this->germany, 'test_1', ['is_test' => true]),
            'claimed, switched off' => $this->claimed($this->germany, 'manual_5', ['status' => SubscriptionStatus::Disabled]),
            'another customer\'s' => $this->claimed($this->germany, 'manual_6', ['user_id' => $bob->id]),
        ];
        $page = Subscription::query()->with('plan')->orderBy('id')->get();

        $plansRead = 0;
        $pickable = $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query) use (&$plansRead): void {
            if (str_contains($query->sql, 'from "plans"')) {
                ++$plansRead;
            }
        }, fn() => $this->renewal()->pickable($page));

        self::assertSame([
            'its own plan' => false,
            'its own plan, switched off' => false,
            'claimed in Germany' => true,
            'claimed in France' => true,
            'claimed in France, ended' => true,
            'claimed where nothing is sold' => false,
            'a free test' => false,
            'claimed, switched off' => false,
            'another customer\'s' => true,
        ], array_map(static fn(Subscription $service): bool => $pickable[$service->id], $services), 'one with its own plan renews on it (plansFor()); whose it is is the caller\'s to choose');
        self::assertSame(1, $plansRead, 'the plans on sale read once for three servers');

        $plansRead = 0;
        $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query) use (&$plansRead): void {
            if (str_contains($query->sql, 'from "plans"')) {
                ++$plansRead;
            }
        }, fn() => $this->renewal()->pickable($page->whereNotNull('plan_id')));
        self::assertSame(0, $plansRead, 'none read for a page without a service to pick for');
    }

    public function testTheRenewalScreenListsServicesWithoutAPlanButNoTestsAndNothingSwitchedOffGoneOrOthers(): void
    {
        $sold = $this->subscription($this->ali, $this->monthly, $this->germany, 'sold_1');
        $claimed = $this->claimed($this->germany, 'manual_1');
        $ended = $this->claimed($this->france, 'manual_2', ['status' => SubscriptionStatus::Expired, 'ended_at' => now()]);
        $this->claimed($this->germany, 'test_1', ['is_test' => true]);
        $this->claimed($this->germany, 'manual_3', ['status' => SubscriptionStatus::Disabled]);
        $this->claimed($this->germany, 'manual_4', ['status' => SubscriptionStatus::Deleted]);
        $this->subscription($this->ali, $this->givesNothing, $this->germany, 'sold_2');
        $this->claimed($this->germany, 'manual_5', ['user_id' => $this->customer(['telegram_id' => 7003])->id]);

        self::assertSame([$ended->id, $claimed->id, $sold->id], CustomerRenewal::candidates($this->ali->id)->pluck('id')->all(), 'newest first');
    }

    public function testARenewalOnAPickedPlanMakesItTheServicesPlan(): void
    {
        $this->withoutQr();
        $claimed = $this->mirrored($this->claimed($this->germany, 'manual_1'));
        $this->service(WalletService::class)->credit($this->ali, '300000.00', 'test');

        $order = $this->service(OrderService::class)->openRenewal($this->ali, $claimed, $this->quarterly, RenewalMethod::Extend);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, (string) $order->notes);
        $claimed->refresh();
        self::assertSame($this->quarterly->id, $claimed->plan_id);
        self::assertSame($this->quarterly->id, $this->renewal()->planFor($claimed)?->id, 'the next renewal is on it');
        self::assertFalse(CustomerRenewal::picksPlan($claimed));
        self::assertSame([], $this->renewal()->choices($claimed)->all());
        self::assertSame('2027-01-17 12:00:00', $claimed->expires_at?->toDateTimeString(), 'its deadline (19 October) plus 90 days');
        self::assertSame(110 * Traffic::GIGABYTE, FakeProvider::lastUpdate('manual_1')->totalBytes);
        self::assertSame([1, 3], [$claimed->ip_limit, $claimed->next_period_ip_limit], 'the plan\'s IP limit, as every renewal gives — once the period in use ends');
        self::assertSame('2026-10-10 12:00:00', $claimed->claimed_at?->toDateTimeString(), 'still the service claimed then');
    }

    /**
     * A service ali claimed by its link two days ago (claimedSubscription()): no plan, 5 GB used of 20, until 19 October,
     * one device.
     *
     * @param array<string, mixed> $overrides
     */
    private function claimed(Server $server, string $name, array $overrides = []): Subscription
    {
        return $this->claimedSubscription($this->ali, $server, $name, $overrides + [
            'claimed_at' => now()->subDays(2),
            'traffic_limit_bytes' => 20 * Traffic::GIGABYTE,
            'download_bytes' => 5 * Traffic::GIGABYTE,
            'starts_at' => now()->subDays(2),
            'expires_at' => Carbon::parse('2026-10-19 12:00:00'),
        ]);
    }

    private function renewal(): CustomerRenewal
    {
        return $this->service(CustomerRenewal::class);
    }

    /** The plan a preview renews on; null for none, or a package built. */
    private function planOf(?RenewalPreview $preview): ?int
    {
        return $preview?->with instanceof Plan ? $preview->with->id : null;
    }
}
