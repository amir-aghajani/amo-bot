<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\Models\LoyaltyTier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\Forms\PrizePlan;
use App\Modules\Rewards\Models\CashbackRule;
use App\Modules\Rewards\Models\LotteryDraw;
use App\Modules\Rewards\Services\Lottery;
use App\Modules\Rewards\Services\LotterySettings;
use App\Modules\Users\Models\User;
use App\Support\Validation;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The loyalty, cashback and draw screens: tiers (unique name and threshold, percents to 100, customers counted), cashback
 * rules, the `cashback` and `lottery` settings groups, the draws with their winners, and a customer's standing. Each shop
 * its own.
 */
final class AdminLoyaltyApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->loginAsAdmin();
    }

    public function testTiersAreMadeChangedListedByTheirThresholdAndDeleted(): void
    {
        $gold = $this->postJson('/api/admin/loyalty/tiers', ['name' => ' طلایی ', 'min_spent' => '۵۰۰٬۰۰۰', 'discount_percent' => 5, 'cashback_percent' => '۲']);
        self::assertSame(201, $gold->getStatusCode(), (string) $gold->getBody());
        $goldId = $this->decode($gold)['tier']['id'];
        self::assertSame(['طلایی', '500000.00', 5, 2, 0], array_values(array_diff_key($this->decode($gold)['tier'], ['id' => 0])));
        $this->customer();
        $bronze = $this->decode($this->postJson('/api/admin/loyalty/tiers', ['name' => 'برنزی', 'min_spent' => '0', 'discount_percent' => 0, 'cashback_percent' => 0]))['tier'];
        self::assertSame(1, $bronze['customers'], 'a tier from nothing spent holds every customer, one who bought nothing too');

        self::assertSame(['برنزی', 'طلایی'], array_column($this->decode($this->get('/api/admin/loyalty/tiers'))['tiers'], 'name'), 'the lowest threshold first');

        $changed = $this->putJson("/api/admin/loyalty/tiers/{$goldId}", ['name' => 'طلایی', 'min_spent' => '400000', 'discount_percent' => 7, 'cashback_percent' => 0]);
        self::assertSame([7, '400000.00'], [$this->decode($changed)['tier']['discount_percent'], $this->decode($changed)['tier']['min_spent']]);

        self::assertSame(204, $this->deleteJson("/api/admin/loyalty/tiers/{$goldId}")->getStatusCode());
        self::assertSame(1, LoyaltyTier::query()->count());
        self::assertSame(404, $this->deleteJson("/api/admin/loyalty/tiers/{$goldId}")->getStatusCode());
    }

    public function testATierIsRefusedFieldByField(): void
    {
        $this->loyaltyTier('طلایی', '500000');

        $refused = $this->postJson('/api/admin/loyalty/tiers', ['name' => 'طلایی', 'min_spent' => '500000', 'discount_percent' => 101, 'cashback_percent' => 'x']);

        self::assertSame(422, $refused->getStatusCode());
        $errors = $this->decode($refused)['errors'];
        self::assertSame(['name', 'min_spent', 'discount_percent', 'cashback_percent'], array_keys($errors), 'every refusal at once');
        self::assertStringContainsString('وجود دارد', $errors['name'][0]);
        self::assertStringContainsString('همین حد خرید', $errors['min_spent'][0]);
        self::assertSame(422, $this->postJson('/api/admin/loyalty/tiers', ['name' => 'نقره‌ای', 'min_spent' => '1500.5', 'discount_percent' => 0, 'cashback_percent' => 0])->getStatusCode(), 'whole Toman');
    }

    public function testEachTierCountsTheCustomersOnItNow(): void
    {
        $plan = $this->plan(['price' => '120000.00']);
        $server = $this->fakeServer();
        $ali = $this->customer();
        $sara = $this->customer(['telegram_id' => 2002]);
        $this->delivered($ali, $plan, $server, '300000');
        $this->delivered($sara, $plan, $server, '150000');
        $this->delivered($sara, $plan, $server, '90000', OrderStatus::Refunded);
        $this->loyaltyTier('نقره‌ای', '100000');
        $this->loyaltyTier('طلایی', '250000');

        $tiers = $this->decode($this->get('/api/admin/loyalty/tiers'))['tiers'];

        self::assertSame(['نقره‌ای' => 1, 'طلایی' => 1], array_column($tiers, 'customers', 'name'));
    }

    public function testCashbackRulesAreMadeChangedSwitchedAndDeleted(): void
    {
        $vip = $this->customerGroup('VIP');
        $card = $this->cardMethod();

        $made = $this->postJson('/api/admin/cashback/rules', ['percent' => '۱۰', 'order_types' => ['renewal', 'purchase', 'purchase'], 'customer_group_id' => $vip->id, 'payment_method_id' => $card->id]);
        self::assertSame(201, $made->getStatusCode(), (string) $made->getBody());
        $rule = $this->decode($made)['rule'];
        self::assertSame([10, ['purchase', 'renewal'], ['id' => $vip->id, 'name' => 'VIP'], ['id' => $card->id, 'label' => $card->label], true], [$rule['percent'], $rule['order_types'], $rule['customer_group'], $rule['payment_method'], $rule['enabled']], 'its kinds in their own order, each once; a new rule on');

        $any = $this->decode($this->postJson('/api/admin/cashback/rules', ['percent' => 3, 'order_types' => ['extra_traffic'], 'customer_group_id' => null, 'enabled' => false]))['rule'];
        self::assertSame([null, null, false], [$any['customer_group'], $any['payment_method'], $any['enabled']]);

        $changed = $this->decode($this->putJson("/api/admin/cashback/rules/{$rule['id']}", ['percent' => 12, 'order_types' => ['extra_time']]))['rule'];
        self::assertSame([12, ['extra_time'], null, null, true], [$changed['percent'], $changed['order_types'], $changed['customer_group'], $changed['payment_method'], $changed['enabled']], 'any group, any way, its switch kept');

        self::assertFalse($this->decode($this->patchJson("/api/admin/cashback/rules/{$rule['id']}", ['enabled' => false]))['rule']['enabled']);
        self::assertSame(422, $this->unchecked()->patchJson("/api/admin/cashback/rules/{$rule['id']}", ['enabled' => 'maybe'])->getStatusCode());
        self::assertSame([$rule['id'], $any['id']], array_column($this->decode($this->get('/api/admin/cashback/rules'))['rules'], 'id'), 'in the order they were made');

        self::assertSame(204, $this->deleteJson("/api/admin/cashback/rules/{$rule['id']}")->getStatusCode());
        self::assertSame(1, CashbackRule::query()->count());
    }

    public function testACashbackRuleIsRefusedFieldByField(): void
    {
        $refused = $this->unchecked()->postJson('/api/admin/cashback/rules', ['percent' => 0, 'order_types' => ['wallet_topup'], 'customer_group_id' => 999, 'payment_method_id' => 999]);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['percent', 'order_types', 'customer_group_id', 'payment_method_id'], array_keys($this->decode($refused)['errors']), 'every refusal at once: a top-up earns no cashback');
        self::assertSame(422, $this->postJson('/api/admin/cashback/rules', ['percent' => 5, 'order_types' => []])->getStatusCode(), 'a kind at least');
    }

    public function testTheCashbackAndDrawSettingsAreGroupsOfTheBotSettings(): void
    {
        $saved = $this->putJson('/api/admin/bot/settings/cashback', ['cashback_enabled' => true]);
        self::assertTrue($this->decode($saved)['settings']['cashback_enabled']);

        $plan = $this->plan();
        $lottery = $this->putJson('/api/admin/bot/settings/lottery', [
            'lottery_enabled' => true,
            'lottery_hour' => '۲۱',
            'lottery_winners' => 3,
            'lottery_prize' => 'plan',
            'lottery_plan_id' => $plan->id,
            'lottery_entries' => 'buyer',
            'lottery_exclude_agents' => false,
        ]);
        self::assertSame(200, $lottery->getStatusCode(), (string) $lottery->getBody());
        $settings = $this->decode($lottery)['settings'];
        self::assertSame([true, 21, 3, 'plan', $plan->id, '50000.00', 'buyer', false], [$settings['lottery_enabled'], $settings['lottery_hour'], $settings['lottery_winners'], $settings['lottery_prize'], $settings['lottery_plan_id'], $settings['lottery_wallet'], $settings['lottery_entries'], $settings['lottery_exclude_agents']], 'the wallet\'s amount not read with a plan prize: kept as it was');
        self::assertSame($plan->id, $this->service(LotterySettings::class)->prize()?->plan?->id);

        $refused = $this->unchecked()->putJson('/api/admin/bot/settings/lottery', [
            'lottery_enabled' => 'yes',
            'lottery_hour' => 24,
            'lottery_winners' => 0,
            'lottery_prize' => 'plan',
            'lottery_plan_id' => 999,
            'lottery_entries' => 'everyone',
            'lottery_exclude_agents' => true,
        ]);
        self::assertSame(422, $refused->getStatusCode());
        $errors = $this->decode($refused)['errors'];
        self::assertSame([Validation::NOT_A_SWITCH], $errors['lottery_enabled']);
        self::assertSame([PrizePlan::NOT_A_PLAN], $errors['lottery_plan_id']);
        self::assertSame(['lottery_enabled', 'lottery_hour', 'lottery_winners', 'lottery_plan_id', 'lottery_entries'], array_keys($errors));
        self::assertSame(422, $this->putJson('/api/admin/bot/settings/lottery', ['lottery_enabled' => true, 'lottery_hour' => 22, 'lottery_winners' => 1, 'lottery_prize' => 'wallet', 'lottery_wallet' => '500', 'lottery_entries' => 'order', 'lottery_exclude_agents' => true])->getStatusCode(), 'a prize of 1,000 Toman at least');
    }

    public function testTheDrawsAreListedNewestFirstWithTheirWinners(): void
    {
        $plan = $this->plan();
        $server = $this->fakeServer();
        $ali = $this->customer(['username' => 'ali']);
        $order = $this->purchaseOrder($ali, $plan, $server, ['status' => OrderStatus::Fulfilled, 'fulfilled_at' => '2026-10-10 12:00:00']);
        $this->cardPayment($order, $this->cardMethod(), ['status' => PaymentStatus::Paid, 'paid_at' => '2026-10-10 12:00:00']);
        $this->lotteryOn(['lottery_wallet' => '20000']);
        Carbon::setTestNow('2026-10-09 22:30:00');
        $this->service(Lottery::class)->drawIfDue();
        Carbon::setTestNow('2026-10-10 22:30:00');
        $this->service(Lottery::class)->drawIfDue();

        $response = $this->get('/api/admin/lottery/draws');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $draws = $this->decode($response)['draws'];
        self::assertSame(['2026-10-10', '2026-10-09'], array_column($draws, 'day'));
        self::assertSame([1, 0], array_column($draws, 'entries'));
        self::assertSame(['kind' => 'wallet', 'amount' => '20000.00', 'plan' => null, 'words' => '۲۰٬۰۰۰ تومان شارژ کیف پول'], $draws[0]['prize']);
        self::assertSame(['ali', '۲۰٬۰۰۰ تومان شارژ کیف پول'], [$draws[0]['winners'][0]['customer']['username'], $draws[0]['winners'][0]['outcome']]);
        self::assertSame([], $draws[1]['winners']);
    }

    public function testACustomersPageSaysWhereTheyStand(): void
    {
        $plan = $this->plan(['price' => '120000.00']);
        $ali = $this->customer();
        $this->delivered($ali, $plan, $this->fakeServer(), '120000');
        $silver = $this->loyaltyTier('نقره‌ای', '100000', 3, 1);
        $this->loyaltyTier('طلایی', '300000', 5, 2);

        $loyalty = $this->decode($this->get("/api/admin/users/{$ali->id}"))['loyalty'];

        self::assertSame([
            'tier' => ['id' => $silver->id, 'name' => 'نقره‌ای', 'discount_percent' => 3, 'cashback_percent' => 1],
            'spent' => '120000.00',
            'next' => ['name' => 'طلایی', 'min_spent' => '300000.00', 'missing' => '180000.00'],
        ], $loyalty);
    }

    public function testAnAgentKeepsTheirOwnShopsTiersRulesAndDraws(): void
    {
        $this->loyaltyTier('طلایی', '500000');
        $this->cashbackRule(10);
        LotteryDraw::query()->create(['day' => '2026-10-10', 'entries' => 0, 'prize_kind' => 'wallet', 'prize_amount' => '1000']);
        $bot = $this->agentBot();

        $this->loginAsAgent($bot);
        self::assertSame([], $this->decode($this->get('/api/agent/loyalty/tiers'))['tiers']);
        self::assertSame([], $this->decode($this->get('/api/agent/cashback/rules'))['rules']);
        self::assertSame([], $this->decode($this->get('/api/agent/lottery/draws'))['draws']);
        $made = $this->decode($this->postJson('/api/agent/loyalty/tiers', ['name' => 'طلایی', 'min_spent' => '100000', 'discount_percent' => 2, 'cashback_percent' => 1]))['tier'];
        self::assertSame(2, LoyaltyTier::query()->withoutGlobalScope(CurrentBot::SCOPE)->count(), 'one name in each shop');
        self::assertSame(404, $this->deleteJson('/api/agent/loyalty/tiers/' . LoyaltyTier::query()->value('id'))->getStatusCode(), "the main shop's tier is not theirs");
        self::assertSame(204, $this->deleteJson("/api/agent/loyalty/tiers/{$made['id']}")->getStatusCode());
    }

    /** A purchase of the customer's at `$amount`, delivered — or in `$status` — with its payment. */
    private function delivered(User $user, Plan $plan, Server $server, string $amount, OrderStatus $status = OrderStatus::Fulfilled): void
    {
        $order = $this->purchaseOrder($user, $plan, $server, ['status' => $status, 'amount' => $amount, 'type' => OrderType::Purchase]);
        $this->cardPayment($order, $this->cardMethod(), ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
    }
}
