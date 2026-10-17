<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agency\Services\AgencyStanding;
use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Enums\WheelRefusal;
use App\Modules\Rewards\Exceptions\WheelRefusedException;
use App\Modules\Rewards\Models\WheelPrize;
use App\Modules\Rewards\Models\WheelSpin;
use App\Modules\Rewards\Services\LuckyWheel;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * The lucky wheel: the switch and the buyers-only rule, one spin per cooldown (two taps, one spin), the cost refunded when
 * the prize cannot be given, a weighted draw among the slices that can reach the customer (Toman or nothing alone in an
 * agent's shop whose selling is suspended), and each kind of prize.
 */
final class LuckyWheelTest extends DatabaseTestCase
{
    private Server $server;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->withoutQr();
        $this->telegram();
        $this->server = $this->sellingServer();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->wheelOn();
    }

    public function testASpinGivesItsPrizeAndTheNextWaitsTheCooldown(): void
    {
        $this->wheelPrize('۱۰ هزار تومان');

        $spun = $this->wheel()->spin($this->ali);

        self::assertSame(['۱۰ هزار تومان'], $spun->slices);
        self::assertSame(0, $spun->landed);
        self::assertSame(RewardKind::Wallet, $spun->given?->reward->kind);
        self::assertSame('10000.00', $this->ali->balance());
        self::assertSame(LuckyWheel::REASON, WalletTransaction::query()->sole()->description);
        $spin = WheelSpin::query()->sole();
        self::assertSame([$this->ali->id, '۱۰ هزار تومان', null], [$spin->user_id, $spin->label, $spin->outcome]);

        $refused = $this->refused(fn() => $this->wheel()->spin($this->ali));
        self::assertSame(WheelRefusal::TooSoon, $refused->reason);
        self::assertSame('2026-10-08T12:00:00+00:00', $refused->nextAt?->toIso8601String());

        Carbon::setTestNow(now()->addHours(24)->addSecond());
        $this->wheel()->spin($this->ali);
        self::assertSame('20000.00', $this->ali->balance(), 'a day on, the next spin');
    }

    public function testOfTwoTapsOneSpins(): void
    {
        $this->wheelPrize();

        $second = null;
        $this->whileListening('eloquent.created: ' . WheelSpin::class, function () use (&$second): void {
            if ($second === null) {
                $second = $this->refused(fn() => $this->wheel()->spin($this->ali));
            }
        }, fn() => $this->wheel()->spin($this->ali));

        self::assertSame(WheelRefusal::TooSoon, $second?->reason);
        self::assertSame(1, WheelSpin::query()->count());
        self::assertSame('10000.00', $this->ali->balance());
    }

    public function testBuyersOnlyAndTheBotsAdminsEitherWay(): void
    {
        $this->wheelOn(['wheel_buyers_only' => true]);
        $this->wheelPrize();

        self::assertSame(WheelRefusal::BuyersOnly, $this->refused(fn() => $this->wheel()->spin($this->ali))->reason);

        $boss = $this->admin(['telegram_id' => 7010]);
        $this->wheel()->spin($boss);
        self::assertSame('10000.00', $boss->balance(), 'the bot\'s admins spin either way');

        $this->buy($this->ali, $this->plan(['name' => 'تست رایگان', 'price' => '0.00'], $this->server), $this->server);
        self::assertSame(WheelRefusal::BuyersOnly, $this->refused(fn() => $this->wheel()->spin($this->ali))->reason, 'a free trial is no purchase here');

        $paid = $this->plan([], $this->server);
        $refunded = $this->buy($this->ali, $paid, $this->server);
        self::assertTrue($this->service(PaymentService::class)->refund($refunded->payments()->firstOrFail(), 'admin', null));
        self::assertSame(WheelRefusal::BuyersOnly, $this->refused(fn() => $this->wheel()->spin($this->ali))->reason, 'nor one refunded since');

        $this->buy($this->ali, $paid, $this->server);
        $this->wheel()->spin($this->ali);
        self::assertSame(1, WheelSpin::query()->where('user_id', $this->ali->id)->count(), 'once they bought, they spin');
    }

    public function testOffAndNothingToWinAreRefused(): void
    {
        self::assertSame(WheelRefusal::Empty, $this->refused(fn() => $this->wheel()->spin($this->ali))->reason, 'no slices');

        $this->wheelPrize('۵ گیگ', ['kind' => RewardKind::Traffic, 'amount' => '5']);
        self::assertSame(WheelRefusal::Empty, $this->refused(fn() => $this->wheel()->spin($this->ali))->reason, 'traffic, and no running service');

        $this->wheelOn(['wheel_enabled' => false]);
        self::assertSame(WheelRefusal::Off, $this->refused(fn() => $this->wheel()->spin($this->ali))->reason);
        self::assertSame(0, WheelSpin::query()->count());
    }

    public function testTheCostIsChargedAndAShortWalletSpinsNothing(): void
    {
        $this->wheelOn(['wheel_cost' => '5,000']);
        $this->wheelPrize('پوچ', ['kind' => RewardKind::Nothing, 'amount' => null]);
        $this->wallet($this->ali, '3000');

        $refused = $this->refused(fn() => $this->wheel()->spin($this->ali));
        self::assertSame(WheelRefusal::Short, $refused->reason);
        self::assertSame('3000.00', $refused->balance);
        self::assertSame(0, WheelSpin::query()->count());

        $this->wallet($this->ali, '8000');
        $spun = $this->wheel()->spin($this->ali);
        self::assertSame(RewardKind::Nothing, $spun->given?->reward->kind);
        self::assertSame('3000.00', $this->ali->balance(), 'the spin cost 5,000; «پوچ» gives nothing');
        self::assertSame(LuckyWheel::LINE_COST, WalletTransaction::query()->latest('id')->first()?->description);
    }

    public function testAnAgentsCreditDoesNotPayASpin(): void
    {
        $this->wheelOn(['wheel_cost' => 5000]);
        $this->wheelPrize();
        $agent = $this->agent(credit: '100000', overrides: ['telegram_id' => 7005]);

        self::assertSame(WheelRefusal::Short, $this->refused(fn() => $this->wheel()->spin($agent))->reason);
    }

    public function testAPrizeThatCouldNotBeGivenGivesTheCostBackAndCountsForNoCooldown(): void
    {
        $this->wheelOn(['wheel_cost' => 5000]);
        $this->wallet($this->ali, '5000');
        $service = $this->runningService();
        $this->wheelPrize('۵ گیگ', ['kind' => RewardKind::Traffic, 'amount' => '5']);
        FakeProvider::$unreachable = true;

        $spun = $this->wheel()->spin($this->ali);

        self::assertNull($spun->given);
        self::assertTrue($spun->refunded);
        self::assertNotNull($spun->failure);
        self::assertSame('5000.00', $this->ali->balance(), 'the cost given back');
        self::assertSame([LuckyWheel::LINE_COST, LuckyWheel::LINE_REFUND], WalletTransaction::query()->orderBy('id')->pluck('description')->slice(1)->values()->all());
        self::assertSame($spun->failure, WheelSpin::query()->sole()->outcome);
        self::assertSame(Traffic::bytesOfGb(30), $service->refresh()->traffic_limit_bytes);

        FakeProvider::$unreachable = false;
        $this->server->forceFill(['last_error' => null])->save();
        $spun = $this->wheel()->spin($this->ali);
        self::assertNotNull($spun->given, 'the failed spin counted for no cooldown');
        self::assertSame(Traffic::bytesOfGb(35), $service->refresh()->traffic_limit_bytes);
    }

    public function testAServiceWonWhoseDeliveryBrokeIsWonOnceAndWaitsForSupport(): void
    {
        $this->wheelOn(['wheel_cost' => 5000]);
        $this->wallet($this->ali, '5000');
        $this->wheelPrize('سرویس هدیه', ['kind' => RewardKind::Plan, 'amount' => null, 'plan_id' => $this->plan(['name' => 'هدیه'], $this->server)->id]);
        FakeProvider::$onCall = static fn() => throw new \RuntimeException('A fault of the shop\'s own.');

        $spun = $this->wheel()->spin($this->ali);

        self::assertFalse($spun->refunded, 'won: its service waits for support\'s retry');
        $spin = WheelSpin::query()->with('order')->sole();
        self::assertSame([null, OrderStatus::Failed], [$spin->outcome, $spin->order?->status]);
        self::assertSame('0.00', $this->ali->balance(), 'the cost kept');
        self::assertSame(WheelRefusal::TooSoon, $this->refused(fn() => $this->wheel()->spin($this->ali))->reason, 'it counts for the cooldown: no second service');
    }

    public function testASliceIsDrawnAmongTheEnabledOnesThatCanReachTheCustomer(): void
    {
        $this->wheelPrize('خاموش', ['enabled' => false, 'weight' => 10_000]);
        $this->wheelPrize('۷ روز', ['kind' => RewardKind::Days, 'amount' => '7', 'weight' => 10_000]);
        $this->wheelPrize('۵ گیگ', ['kind' => RewardKind::Traffic, 'amount' => '5', 'weight' => 10_000]);
        $this->wheelPrize('پوچ', ['kind' => RewardKind::Nothing, 'amount' => null]);

        $spun = $this->wheel()->spin($this->ali);

        self::assertSame(['۷ روز', '۵ گیگ', 'پوچ'], $spun->slices, 'the enabled slices, in order');
        self::assertSame(2, $spun->landed, 'no running service: neither days nor traffic can reach them');
        self::assertSame('پوچ', $spun->spin->label);
    }

    public function testEachKindIsGiven(): void
    {
        $service = $this->runningService();
        $plan = $this->plan(['name' => 'هدیه'], $this->server);
        $prizes = [
            ['۷ روز', ['kind' => RewardKind::Days, 'amount' => '7']],
            ['۵ گیگ', ['kind' => RewardKind::Traffic, 'amount' => '5']],
            ['سرویس هدیه', ['kind' => RewardKind::Plan, 'amount' => null, 'plan_id' => $plan->id]],
        ];
        foreach ($prizes as [$label, $overrides]) {
            Carbon::setTestNow(now()->addDay()->addMinute());
            $prize = $this->wheelPrize($label, $overrides);
            $spun = $this->wheel()->spin($this->ali);
            $prize->forceFill(['enabled' => false])->save();

            self::assertSame($label, $spun->spin->label);
            self::assertNotNull($spun->given, $label);
        }

        $service->refresh();
        self::assertSame(Traffic::bytesOfGb(35), $service->traffic_limit_bytes);
        $spins = WheelSpin::query()->with('order')->orderBy('id')->get();
        self::assertSame([$service->id, $service->id], [$spins[0]->subscription_id, $spins[1]->subscription_id]);
        $order = $spins[2]->order ?? self::fail('no order');
        self::assertSame([OrderStatus::Fulfilled, OrderPricing::Gift], [$order->status, $order->pricing]);
        self::assertSame($order->subscription_id, $spins[2]->subscription_id);
    }

    public function testAPlanNoServerDeliversIsNeverDrawn(): void
    {
        $this->wheelPrize('سرویس', ['kind' => RewardKind::Plan, 'amount' => null, 'plan_id' => $this->plan()->id, 'weight' => 10_000]);
        $this->wheelPrize('پوچ', ['kind' => RewardKind::Nothing, 'amount' => null]);

        self::assertSame('پوچ', $this->wheel()->spin($this->ali)->spin->label);
    }

    public function testAnAgentsShopHasItsOwnWheel(): void
    {
        $this->wheelPrize();
        $bot = $this->agentBot();

        CurrentBot::run($bot, function (): void {
            $reza = $this->customer(['telegram_id' => 7003]);
            self::assertSame(WheelRefusal::Off, $this->refused(fn() => $this->wheel()->spin($reza))->reason);

            $this->wheelOn();
            $this->wheelPrize('۲۰ هزار تومان', ['amount' => '20000']);
            $this->wheel()->spin($reza);
            self::assertSame('20000.00', $reza->balance(), 'its own slices');
        });
    }

    public function testAnAgentsShopWhoseSellingIsSuspendedDrawsOnlyTomanOrNothing(): void
    {
        $bot = $this->agentBot();
        $reza = CurrentBot::run($bot, function (): User {
            $reza = $this->wallet($this->customer(['telegram_id' => 7003]), '5000');
            $plan = $this->plan(['name' => 'هدیه'], $this->server);
            $this->subscription($reza, $plan, $this->server, 'reza_1', ['expires_at' => now()->addDays(20)]);
            $this->wheelOn(['wheel_cost' => 1000]);
            $this->wheelPrize('۵ گیگ', ['kind' => RewardKind::Traffic, 'amount' => '5', 'weight' => 10_000]);
            $this->wheelPrize('۷ روز', ['kind' => RewardKind::Days, 'amount' => '7', 'weight' => 10_000]);
            $this->wheelPrize('سرویس هدیه', ['kind' => RewardKind::Plan, 'amount' => null, 'plan_id' => $plan->id, 'weight' => 10_000]);
            $this->wheelPrize('۲ هزار تومان', ['amount' => '2000']);

            return $reza;
        });
        $this->service(AgencyStanding::class)->suspendByHand($bot, null);

        CurrentBot::run($bot->refresh(), function () use ($reza): void {
            $spun = $this->wheel()->spin($reza);
            self::assertSame('۲ هزار تومان', $spun->spin->label, 'a service, traffic or days the shop gives none of now is never drawn');
            self::assertNotNull($spun->given);
            self::assertSame('6000.00', $reza->balance(), 'a spin of 1,000 won 2,000: none drawn to be refunded');

            WheelPrize::query()->where('kind', RewardKind::Wallet)->update(['enabled' => false]);
            Carbon::setTestNow(now()->addDay()->addMinute());
            self::assertSame(WheelRefusal::Empty, $this->refused(fn() => $this->wheel()->spin($reza))->reason, 'nothing left it gives');
            self::assertSame(['6000.00', 1], [$reza->balance(), WheelSpin::query()->count()], 'nothing spent');
        });
    }

    /** A running service of Ali's on the server: 30 GB, ending in 20 days, its panel mirroring it. */
    private function runningService(): Subscription
    {
        $service = $this->subscription($this->ali, $this->plan([], $this->server), $this->server, 'ali_1', ['expires_at' => now()->addDays(20)]);
        FakeProvider::mirror($service);

        return $service;
    }

    /** @param \Closure(): mixed $spin */
    private function refused(\Closure $spin): WheelRefusedException
    {
        try {
            $spin();
        } catch (WheelRefusedException $e) {
            return $e;
        }

        self::fail('refused');
    }

    private function wheel(): LuckyWheel
    {
        return $this->service(LuckyWheel::class);
    }
}
