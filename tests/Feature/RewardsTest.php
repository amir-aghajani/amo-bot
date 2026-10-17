<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Exceptions\RewardUnavailableException;
use App\Modules\Rewards\Services\Eligibility;
use App\Modules\Rewards\Services\Rewards;
use App\Modules\Subscriptions\Exceptions\TermTooLongException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Support\LocalTime;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * Rewards::give(): Toman to the wallet, traffic or days to the newest running service that takes them (or the one
 * named), and a plan's free service — an order at no price, delivered and sent, never the customer's first purchase.
 */
final class RewardsTest extends DatabaseTestCase
{
    private Server $server;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->telegram();
        $this->server = $this->sellingServer();
        $this->ali = $this->customer(['username' => 'ali']);
    }

    public function testToman(): void
    {
        $this->rewards()->give($this->ali, Reward::wallet('25000'), 'جایزه گردونه شانس');

        self::assertSame('25000.00', $this->ali->balance());
        self::assertSame('جایزه گردونه شانس', WalletTransaction::query()->where('user_id', $this->ali->id)->sole()->description);
    }

    public function testTrafficAndDaysGoToTheNewestRunningServiceThatTakesThem(): void
    {
        $plan = $this->plan([], $this->server);
        $older = $this->subscription($this->ali, $plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(10)]);
        $newer = $this->subscription($this->ali, $plan, $this->server, 'ali_2', ['expires_at' => now()->addDays(20)]);
        FakeProvider::mirror($older);
        FakeProvider::mirror($newer);

        $given = $this->rewards()->give($this->ali, Reward::traffic(5), 'جایزه');
        self::assertSame($newer->id, $given->subscription?->id);
        self::assertSame(Traffic::bytesOfGb(35), $newer->refresh()->traffic_limit_bytes, 'on top of its 30 GB');

        $given = $this->rewards()->give($this->ali, Reward::days(7), 'جایزه', $older);
        self::assertSame($older->id, $given->subscription?->id, 'the one named');
        self::assertSame(now()->addDays(17)->startOfMinute()->toIso8601String(), $older->refresh()->expires_at?->startOfMinute()->toIso8601String());
    }

    public function testDaysOrAPlanThatWouldEndAServicePastTheLastDayTheShopKeepsAreNotGiven(): void
    {
        // 20 days before the TIMESTAMP columns' last moment (MySQL refuses a later one).
        Carbon::setTestNow(LocalTime::last()->subDays(20));
        $plan = $this->plan([], $this->server);
        $older = $this->mirrored($this->subscription($this->ali, $plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(5)]));
        $newer = $this->mirrored($this->subscription($this->ali, $plan, $this->server, 'ali_2', ['expires_at' => now()->addDays(15)]));

        $given = $this->rewards()->give($this->ali, Reward::days(10), 'جایزه');
        self::assertSame($older->id, $given->subscription?->id, 'the newest would pass it: the next that takes them');
        self::assertEquals(now()->addDays(15), $older->refresh()->expires_at);
        self::assertNotSame([], $this->eligibility()->givable($this->ali, [Reward::days(5)]), 'five more still fit');
        self::assertSame([], $this->eligibility()->givable($this->ali, [Reward::days(6), Reward::plan($plan)]), 'six fit neither, nor does a 30-day plan: no slice of either is drawn');

        try {
            $this->rewards()->give($this->ali, Reward::plan($plan), 'جایزه');
            self::fail('The plan was given.');
        } catch (RewardUnavailableException $e) {
            self::assertSame(TermTooLongException::message(), $e->getMessage());
        }
        self::assertSame(0, Order::query()->count(), 'nothing ordered');
    }

    public function testNoServiceTakesItNothingIsGiven(): void
    {
        $this->expectExceptionMessage(RewardUnavailableException::NO_SERVICE);

        $this->rewards()->give($this->ali, Reward::days(7), 'جایزه');
    }

    public function testAPlansServiceGivenFreeIsNoPurchaseOfTheirs(): void
    {
        $plan = $this->plan(['price' => '150000.00', 'first_purchase_price' => '50000.00'], $this->server);

        $given = $this->rewards()->give($this->ali, Reward::plan($plan), 'جایزه');

        $order = $given->order ?? self::fail('no order');
        self::assertSame([OrderStatus::Fulfilled, '0.00', OrderPricing::Gift], [$order->status, $order->amount, $order->pricing]);
        self::assertNotNull($given->subscription, 'its service');
        self::assertCount(1, FakeProvider::$created);
        self::assertSame('0.00', $this->ali->balance(), 'nothing taken, no ledger line');
        self::assertFalse(Order::boughtBy($this->ali->id)->exists(), 'their first purchase is still theirs to make');
        self::assertNotSame([], $this->telegram()->sentTo(self::TELEGRAM_ID), 'its service sent');
    }

    public function testAPlansServiceWhoseDeliveryBrokeIsGivenAndWaitsForSupportsRetry(): void
    {
        $plan = $this->plan([], $this->server);
        $logs = $this->logs();
        FakeProvider::$onCall = static fn() => throw new \RuntimeException('A fault of the shop\'s own.');

        $given = $this->rewards()->give($this->ali, Reward::plan($plan), 'جایزه');

        $order = $given->order ?? self::fail('no order');
        self::assertSame([OrderStatus::Failed, OrderService::DELIVERY_BROKEN], [$order->refresh()->status, $order->notes], 'paid, and waiting for support: given, never thrown for its caller to give again');
        self::assertSame(PaymentStatus::Paid, $given->payment?->status);
        self::assertTrue($logs->hasErrorThatContains("The free service of order {$order->id} was paid but its delivery broke"), 'the fault is the log\'s');

        FakeProvider::$onCall = null;
        $this->service(OrderActions::class)->retry($order->load('payments'));
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, "support's retry delivers it");
        self::assertSame(1, Order::query()->count(), 'one service');
    }

    public function testAPlanNoServerDeliversNowIsNotGiven(): void
    {
        $plan = $this->plan();

        $this->expectExceptionMessage(RewardUnavailableException::NO_SERVER);

        try {
            $this->rewards()->give($this->ali, Reward::plan($plan), 'جایزه');
        } finally {
            self::assertSame(0, Order::query()->count(), 'nothing ordered');
        }
    }

    public function testInAnAgentsShopTrafficComesOutOfTheirPool(): void
    {
        $bot = $this->agentBot(traffic: 20);
        CurrentBot::run($bot, function () use ($bot): void {
            $reza = $this->customer(['username' => 'reza']);
            $service = $this->subscription($reza, $this->plan([], $this->server), $this->server, 'reza_1', ['expires_at' => now()->addDays(20)]);
            FakeProvider::mirror($service);

            $this->rewards()->give($reza, Reward::traffic(5), 'جایزه');

            self::assertSame(Traffic::bytesOfGb(15), $bot->refresh()->trafficBalance());
        });
    }

    private function rewards(): Rewards
    {
        return $this->service(Rewards::class);
    }

    private function eligibility(): Eligibility
    {
        return $this->service(Eligibility::class);
    }
}
