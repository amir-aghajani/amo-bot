<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Exceptions\RewardUnavailableException;
use App\Modules\Rewards\Services\Rewards;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Support\Traffic;
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
}
