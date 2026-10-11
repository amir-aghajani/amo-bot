<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Users\Services\WalletSettings;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;

/**
 * Opening an order: picking a way to pay again finds the same open order (same plan, server and price) and restarts its
 * expiry; anything else is a new order. Top-ups and agents' traffic outside the shop's bounds are refused.
 */
final class OrderOpeningTest extends DatabaseTestCase
{
    public function testTheSamePlanOnTheSameServerAtTheSamePriceFindsTheOpenOrder(): void
    {
        $berlin = $this->fakeServer('Berlin');
        $paris = $this->fakeServer('Paris');
        $plan = $this->plan([], [$berlin, $paris]);
        $ali = $this->customer();
        $orders = $this->service(OrderService::class);

        Carbon::setTestNow('2026-09-18 10:00:00');
        $first = $orders->openPurchase($ali, $plan, $berlin);
        Carbon::setTestNow('2026-09-19 10:00:00');

        self::assertSame($first->id, $orders->openPurchase($ali, $plan, $berlin)->id, 'picked again');
        self::assertSame('2026-09-19 10:00:00', $first->refresh()->updated_at->format('Y-m-d H:i:s'), 'its expiry counts from now');
        self::assertNotSame($first->id, $orders->openPurchase($ali, $plan, $paris)->id, 'on another server, another order');
        self::assertNotSame($first->id, $orders->openPurchase($this->customer(['telegram_id' => 9999]), $plan, $berlin)->id, "someone else's");

        // The admin changed the plan's price since.
        $plan->forceFill(['price' => '150000.00'])->save();
        $repriced = $orders->openPurchase($ali, $plan, $berlin);
        self::assertNotSame($first->id, $repriced->id, 'at another price, another order');
        self::assertSame('150000.00', $repriced->amount);
    }

    public function testATopUpOrTrafficOutsideTheShopsBoundsIsRefusedWhoeverAsks(): void
    {
        $orders = $this->service(OrderService::class);
        $refused = static function (\Closure $open): array {
            try {
                $open();
            } catch (ValidationException $e) {
                return array_keys($e->errors());
            }
            self::fail('ordered');
        };

        $ali = $this->customer();
        self::assertSame(['amount'], $refused(static fn() => $orders->openTopUp($ali, 9_999)), 'below the bot settings\' least');
        self::assertSame(['amount'], $refused(static fn() => $orders->openTopUp($ali, WalletSettings::TOPUP_MAX + 1)), 'past the most');
        $agent = $this->agent(overrides: ['telegram_id' => 7007]);
        self::assertSame(['gb'], $refused(static fn() => $orders->openTraffic($agent, 9)), "below the agency program's least");

        self::assertSame(0, Order::query()->count());
    }
}
