<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Services\AutoRenewal;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Subscriptions\Tasks\AutoRenewTask;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * Agent prices («قیمت نماینده»): paid by agents and «همکار» groups for purchases and renewals, marked on the order; the
 * lower of an agent and a first-purchase price wins; a price not below the plan's is none. An agent's shop marks its own
 * groups.
 */
final class AgentPricesTest extends DatabaseTestCase
{
    private Server $server;
    private Plan $plan;
    private Plan $plain;
    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '150000.00', 'agent_price' => '100000.00'], $this->server);
        $this->plain = $this->plan(['name' => 'سه‌ماهه', 'price' => '300000.00'], $this->server);
        $this->colleague = $this->customer(['telegram_id' => 7002, 'username' => 'sara']);
        $this->customerGroup('همکاران', [$this->colleague], ['agent_pricing' => true]);
    }

    public function testAnAgentAndAColleaguePayItEveryoneElseThePrice(): void
    {
        $agent = $this->agent();
        $vip = $this->customer(['telegram_id' => 7003, 'username' => 'vip']);
        $this->customerGroup('VIP', [$vip]);
        $plain = $this->customer(['telegram_id' => 7004, 'username' => 'ali']);
        $orders = $this->service(OrderService::class);

        foreach ([[$agent, 'an agent'], [$this->colleague, 'a colleague']] as [$customer, $who]) {
            self::assertTrue($this->service(Pricing::class)->buyer($customer, $this->plan)->agent, $who);
            $order = $orders->openPurchase($customer, $this->plan, $this->server);
            self::assertSame(['100000.00', OrderPricing::Agent], [$order->amount, $order->pricing], "{$who}: the agent price");
        }
        foreach ([[$vip, 'a customer of a group without it'], [$plain, 'a customer'], [null, "a website's guest"]] as [$customer, $who]) {
            self::assertFalse($this->service(Pricing::class)->buyer($customer, $this->plan)->agent, $who);
        }
        $order = $orders->openPurchase($vip, $this->plan, $this->server);
        self::assertSame(['150000.00', OrderPricing::Regular], [$order->amount, $order->pricing], 'the plan\'s price');

        $order = $orders->openPurchase($this->colleague, $this->plain, $this->server);
        self::assertSame(['300000.00', OrderPricing::Regular], [$order->amount, $order->pricing], 'a plan without one: its price');
    }

    public function testBoughtAndDeliveredAtIt(): void
    {
        $order = $this->buy($this->colleague, $this->plan, $this->server);

        self::assertSame([OrderStatus::Fulfilled, '100000.00', OrderPricing::Agent], [$order->status, $order->amount, $order->pricing]);
        self::assertSame('50000.00', $this->colleague->balance(), 'the wallet charged the agent price');
        self::assertNotNull($order->subscription);
    }

    public function testTheLowerOfItAndTheFirstPurchasePriceIsPaid(): void
    {
        $this->plan->forceFill(['first_purchase_price' => '50000.00'])->save();
        $orders = $this->service(OrderService::class);

        $first = $this->buy($this->colleague, $this->plan, $this->server);
        self::assertSame(['50000.00', OrderPricing::FirstPurchase], [$first->amount, $first->pricing], 'a newcomer: the first purchase, lower');

        $next = $orders->openPurchase($this->colleague, $this->plan, $this->server);
        self::assertSame(['100000.00', OrderPricing::Agent], [$next->amount, $next->pricing], 'bought: the agent price');

        // A first-purchase price above the agent price: a colleague's first purchase is at the agent price, and so no
        // first purchase at all — nothing holds its delivery to having bought nothing.
        $newcomer = $this->customer(['telegram_id' => 7005, 'username' => 'nima']);
        $this->customerGroup('همکاران ۲', [$newcomer], ['agent_pricing' => true]);
        $this->plan->forceFill(['first_purchase_price' => '120000.00'])->save();
        $order = $orders->openPurchase($newcomer, $this->plan, $this->server);
        self::assertSame(['100000.00', OrderPricing::Agent], [$order->amount, $order->pricing]);
    }

    public function testOneNotBelowThePriceIsNone(): void
    {
        foreach (['100000.00', '90000.00'] as $price) {
            $this->plan->forceFill(['price' => $price])->save();

            self::assertNull($this->plan->agentPrice(), "the price {$price}");
            $order = $this->service(OrderService::class)->openPurchase($this->colleague, $this->plan, $this->server);
            self::assertSame([$price, OrderPricing::Regular], [$order->amount, $order->pricing]);
            $order->delete();
        }
    }

    public function testNobodysGroupsAreAskedAboutWhileNoPlanHasOne(): void
    {
        $pricing = $this->service(Pricing::class);

        $queries = 0;
        $this->whileListening(QueryExecuted::class, static function () use (&$queries): void {
            ++$queries;
        }, fn() => self::assertFalse($pricing->buyer($this->colleague, $this->plain)->agent));

        self::assertSame(0, $queries);
        self::assertTrue($pricing->buyer($this->colleague, $this->plain, $this->plan)->agent, 'a list: one of them has one');
    }

    public function testTheGroupLosingItsMarkEndsIt(): void
    {
        $this->colleague->groups()->sole()->forceFill(['agent_pricing' => false])->save();

        $order = $this->service(OrderService::class)->openPurchase($this->colleague->refresh(), $this->plan, $this->server);

        self::assertSame(['150000.00', OrderPricing::Regular], [$order->amount, $order->pricing]);
    }

    public function testTheirServicesRenewAtItByThemOrByThemselves(): void
    {
        $this->telegram();
        $this->botSettings('auto_renew', ['auto_renew_days' => 2, 'auto_renew_default' => true]);
        $service = $this->buy($this->colleague, $this->plan, $this->server)->subscription ?? self::fail('nothing delivered');

        self::assertSame('100000.00', $this->service(CustomerRenewal::class)->preview($service)?->quote->amount(), 'renewed by its customer at the agent price');

        $this->wallet($this->colleague, '500000.00');
        // The wallet takes 100,000 Toman at most: the agent price, not the plan's.
        $this->walletMethod()->forceFill(['max_amount' => '100000'])->save();
        $service->forceFill(['starts_at' => now()->subDays(29), 'expires_at' => now()->addDay()])->save();
        FakeProvider::mirror($service);
        self::assertTrue($this->service(AutoRenewal::class)->offeredFor($service), 'the switch judged at the price the renewal charges');
        $this->service(AutoRenewTask::class)->run();

        $renewal = Order::query()->where('type', OrderType::Renewal->value)->sole();
        self::assertSame([OrderStatus::Fulfilled, '100000.00', OrderPricing::Agent], [$renewal->status, $renewal->amount, $renewal->pricing], 'and by itself');
        self::assertSame('400000.00', $this->colleague->balance());

        $this->colleague->groups()->detach();
        self::assertSame('150000.00', $this->service(CustomerRenewal::class)->preview($service->refresh())?->quote->amount(), 'out of the group: the plan\'s price');
        self::assertFalse($this->service(AutoRenewal::class)->offeredFor($service), 'more than the wallet takes: renewed by hand');
    }

    public function testAnAgentsShopMarksItsOwnColleagues(): void
    {
        CurrentBot::run($this->agentBot(), function (): void {
            $theirs = $this->plan(['name' => 'یک‌ماهه نماینده', 'price' => '90000.00', 'agent_price' => '70000.00'], $this->server);
            $reza = $this->customer(['username' => 'reza']);
            $this->customerGroup('همکاران', [$reza], ['agent_pricing' => true]);
            $other = $this->customer(['telegram_id' => 7006, 'username' => 'other']);

            self::assertSame(['70000.00', OrderPricing::Agent], [($order = $this->buy($reza, $theirs, $this->server))->amount, $order->pricing], 'a colleague of the agent\'s shop');
            self::assertSame('90000.00', $this->service(OrderService::class)->openPurchase($other, $theirs, $this->server)->amount, 'anyone else there');
        });
    }

    public function testTheGroupHearsAPurchaseWasAtIt(): void
    {
        $this->telegram();
        $this->reportGroup();

        $this->buy($this->colleague, $this->plan, $this->server);
        $this->buy($this->colleague, $this->plain, $this->server);

        $sales = ReportMessage::query()->where('topic', Topic::Purchases)->oldest('id')->pluck('text')->all();
        self::assertCount(2, $sales);
        self::assertStringContainsString('۱۰۰٬۰۰۰ تومان · کیف پول · 🤝 قیمت نماینده', $sales[0]);
        self::assertStringNotContainsString('قیمت نماینده', $sales[1]);
    }
}
