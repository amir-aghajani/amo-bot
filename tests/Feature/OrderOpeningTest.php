<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Users\Services\WalletSettings;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;

/**
 * Opening an order: picking a way to pay again finds the same open order (same plan, server and price — a renewal's
 * method too) and restarts its expiry; anything else is a new order. Top-ups and agents' traffic outside the shop's
 * bounds are refused.
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

    public function testARenewalByAnotherMethodIsAnotherOrder(): void
    {
        $server = $this->fakeServer('Berlin');
        $plan = $this->plan([], $server);
        $ali = $this->customer();
        $service = $this->subscription($ali, $plan, $server, 'ali_1');
        $orders = $this->service(OrderService::class);

        $extend = $orders->openRenewal($ali, $service, $plan, RenewalMethod::Extend);
        self::assertSame(RenewalMethod::Extend, $extend->refresh()->renewal_method);
        self::assertSame($extend->id, $orders->openRenewal($ali, $service, $plan, RenewalMethod::Extend)->id, 'picked again');
        $reset = $orders->openRenewal($ali, $service, $plan, RenewalMethod::ResetTime);
        self::assertNotSame($extend->id, $reset->id, 'by another method, another order');
        self::assertSame(RenewalMethod::ResetTime, $reset->refresh()->renewal_method);

        $key = new OrderKey('renew-once', $this->walletMethod()->id);
        $keyed = $orders->openRenewal($ali, $service, $plan, RenewalMethod::ResetTraffic, $key);
        self::assertSame($keyed->id, $orders->openRenewal($ali, $service, $plan, RenewalMethod::ResetTraffic, $key)->id, 'its key, the same request');
        $this->expectException(RequestKeyReusedException::class);
        $orders->openRenewal($ali, $service, $plan, RenewalMethod::Extend, $key);
    }

    public function testAnAutomaticRenewalIsByTheMethodGiven(): void
    {
        $server = $this->fakeServer('Berlin');
        $plan = $this->plan([], $server);
        $ali = $this->customer();
        $service = $this->subscription($ali, $plan, $server, 'ali_1');
        $orders = $this->service(OrderService::class);

        self::assertSame(RenewalMethod::ResetTime, $orders->createRenewal($ali, $service, $plan, RenewalMethod::ResetTime)->refresh()->renewal_method);
        self::assertSame(RenewalMethod::Extend, $orders->createRenewal($ali, $service, $plan)->refresh()->renewal_method, 'an extension when none is given');
    }

    public function testATopUpOrTrafficOutsideTheShopsBoundsIsRefusedWhoeverAsks(): void
    {
        $orders = $this->service(OrderService::class);
        $ali = $this->customer();
        self::assertSame(['amount'], array_keys($this->refusedWith(static fn() => $orders->openTopUp($ali, 9_999))), 'below the bot settings\' least');
        self::assertSame(['amount'], array_keys($this->refusedWith(static fn() => $orders->openTopUp($ali, WalletSettings::TOPUP_MAX + 1))), 'past the most');
        $agent = $this->agent(overrides: ['telegram_id' => 7007]);
        self::assertSame(['gb'], array_keys($this->refusedWith(static fn() => $orders->openTraffic($agent, 9))), "below the agency program's least");

        self::assertSame(0, Order::query()->count());
    }
}
