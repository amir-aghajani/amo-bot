<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;

/**
 * Codes and sales held to plan categories across the shops and the perks: an agent's code of their own category holds
 * for its plans in their shop, the main shop's category sale never reaches an agent's shop (whatever ids it names), and
 * with «همکارها فقط با قیمت همکاری» on a category sale reaches no one who pays agent prices.
 */
final class CategoryDiscountsAcrossTest extends DatabaseTestCase
{
    private Server $server;
    private PlanCategory $monthly;
    private Plan $plan;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->monthly = $this->category(['name' => 'ماهانه']);
        $this->plan = $this->plan(['price' => '100000.00', 'category_id' => $this->monthly->id], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
    }

    public function testAnAgentsCodeOfTheirOwnCategoryHoldsForItsPlansOnAPurchaseAndARenewal(): void
    {
        $bot = $this->agentBot($this->agent(overrides: ['telegram_id' => 6001]), 500);
        $this->discountCode('MONTHLY', 50, ['category_ids' => [$this->monthly->id]]);

        CurrentBot::run($bot, function (): void {
            $server = $this->sellingServer('آلمان ۲');
            $theirs = $this->category(['name' => 'ماهانه نماینده']);
            $weekly = $this->category(['name' => 'هفتگی نماینده']);
            $plan = $this->plan(['price' => '80000.00', 'traffic_gb' => 10, 'category_id' => $theirs->id], $server);
            $outside = $this->plan(['name' => 'هفتگی', 'price' => '80000.00', 'traffic_gb' => 10, 'category_id' => $weekly->id], $server);
            $customer = $this->customer(['telegram_id' => 9100]);
            $this->discountCode('AGENT', 25, ['category_ids' => [$theirs->id]]);

            self::assertSame('60000.00', $this->pricing()->purchase($customer, $plan, $server, 'agent')->amount(), 'a purchase of a plan in their category');
            $subscription = $this->subscription($customer, $plan, $server, 'c9100_1');
            self::assertSame('60000.00', $this->pricing()->renewal($customer, $subscription, $plan, 'AGENT')->amount(), 'a renewal on it');
            $this->assertRefused(DiscountCodes::WRONG_PLAN, fn() => $this->pricing()->purchase($customer, $outside, $server, 'AGENT'), 'a plan of their other category');
            $this->assertRefused(DiscountCodes::WRONG_PLAN, fn() => $this->pricing()->renewal($customer, $subscription, $outside, 'AGENT'), 'renewed on it');
            $this->assertRefused(DiscountCodes::NOT_FOUND, fn() => $this->pricing()->purchase($customer, $plan, $server, 'MONTHLY'), "the main shop's category code is no code of theirs");
        });
    }

    public function testTheMainShopsCategorySaleNeverReachesAnAgentsShop(): void
    {
        $bot = $this->agentBot($this->agent(overrides: ['telegram_id' => 6001]), 500);
        [$theirs, $plan, $server, $customer] = CurrentBot::run($bot, function (): array {
            $server = $this->sellingServer('آلمان ۲');
            $theirs = $this->category(['name' => 'ماهانه نماینده']);

            return [$theirs, $this->plan(['price' => '80000.00', 'traffic_gb' => 10, 'category_id' => $theirs->id], $server), $server, $this->customer(['telegram_id' => 9100])];
        });
        // Even naming their category's id, as a shop whose ids met theirs would: the shop it is of, alone.
        $this->sale('فروش ماهانه', 50, ['category_ids' => [$this->monthly->id, $theirs->id]]);

        self::assertSame('50000.00', $this->pricing()->purchase($this->ali, $this->plan, $this->server)->amount(), "the main shop's own plan of the category");
        CurrentBot::run($bot, function () use ($plan, $server, $customer): void {
            self::assertSame('80000.00', $this->pricing()->purchase($customer, $plan, $server)->amount(), "the main shop's sale is not this shop's");
            $subscription = $this->subscription($customer, $plan, $server, 'c9100_1');
            self::assertSame('80000.00', $this->pricing()->renewal($customer, $subscription, $plan)->amount(), 'nor on a renewal');

            $this->sale('فروش نماینده', 20, ['category_ids' => [$plan->category_id]]);
            self::assertSame('64000.00', $this->pricing()->purchase($customer, $plan, $server)->amount(), 'their own sale of their category');
        });
    }

    public function testWithAgentsWithoutPerksACategorySaleReachesNoOneWhoPaysAgentPrices(): void
    {
        $plan = $this->plan(['name' => 'همکاری', 'price' => '150000.00', 'agent_price' => '100000.00', 'category_id' => $this->monthly->id], $this->server);
        $colleague = $this->customer(['telegram_id' => 7002, 'username' => 'sara']);
        $this->customerGroup('همکاران', [$colleague], ['agent_pricing' => true]);
        $agent = $this->agent(overrides: ['telegram_id' => 7003]);
        $this->extrasOnSale();
        $subscription = $this->subscription($colleague, $plan, $this->server, 'sara_1');
        $this->sale('فروش ماهانه', 20, ['category_ids' => [$this->monthly->id]]);

        self::assertSame('80000.00', $this->pricing()->purchase($colleague, $plan, $this->server)->amount(), 'off: the category sale off the agent price');

        $this->botSettings('agent_perks', ['agents_without_perks' => true]);

        foreach ([[$colleague, 'a colleague'], [$agent, 'an agent']] as [$who, $label]) {
            $quote = $this->pricing()->purchase($who, $plan, $this->server);
            self::assertSame([OrderPricing::Agent, '100000.00', [], '100000.00'], [$quote->pricing, $quote->base, $quote->lines(), $quote->amount()], "{$label}: the agent price, the category sale not off it");
        }
        self::assertSame('100000.00', $this->pricing()->renewal($colleague, $subscription, $plan)->amount(), 'nor off a renewal on the plan');
        self::assertSame('7000.00', $this->pricing()->extra($colleague, $subscription, ExtraKind::Time, '7000')->amount(), 'nor off an extra of the service on it: its own price');
        self::assertSame('120000.00', $this->pricing()->purchase($this->ali, $plan, $this->server)->amount(), 'everyone else: the regular price less the sale');
    }

    private function pricing(): Pricing
    {
        return $this->service(Pricing::class);
    }

    private function assertRefused(string $words, \Closure $work, string $message): void
    {
        try {
            $work();
        } catch (DiscountCodeRefusedException $e) {
            self::assertSame($words, $e->getMessage(), $message);

            return;
        }
        self::fail('The code held: ' . $message);
    }
}
