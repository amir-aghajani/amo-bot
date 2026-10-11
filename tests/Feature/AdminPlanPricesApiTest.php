<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use Tests\HttpTestCase;

/**
 * Changing several plans' prices at once: by a percent or Toman, rounded to a step, on the prices named; previewed, applied
 * in one go, and refused whole while any plan cannot take it. An agent changes their own shop's.
 */
final class AdminPlanPricesApiTest extends HttpTestCase
{
    private Server $server;
    private Plan $monthly;
    private Plan $quarterly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
        $this->server = $this->sellingServer();
        $this->monthly = $this->plan(['price' => '120000.00', 'first_purchase_price' => '50000.00', 'agent_price' => '100000.00'], $this->server);
        $this->quarterly = $this->plan(['name' => 'سه‌ماهه', 'price' => '300000.00'], $this->server);
    }

    public function testThePreviewSaysWhatTheChangeWouldMakeAndSavesNothing(): void
    {
        $changes = $this->decode($this->postJson('/api/admin/plans/prices/preview', $this->change(['prices' => ['price', 'agent_price']])))['changes'];

        self::assertSame([
            [
                'id' => $this->monthly->id,
                'name' => 'یک‌ماهه',
                'before' => ['price' => '120000.00', 'first_purchase_price' => '50000.00', 'agent_price' => '100000.00'],
                'after' => ['price' => '132000.00', 'first_purchase_price' => '50000.00', 'agent_price' => '110000.00'],
                'problem' => null,
            ],
            [
                'id' => $this->quarterly->id,
                'name' => 'سه‌ماهه',
                'before' => ['price' => '300000.00', 'first_purchase_price' => null, 'agent_price' => null],
                'after' => ['price' => '330000.00', 'first_purchase_price' => null, 'agent_price' => null],
                'problem' => null,
            ],
        ], $changes, 'the first-purchase price not named stays; a price a plan has not, it still has not');
        self::assertSame('120000.00', $this->monthly->refresh()->price, 'nothing saved');
    }

    public function testEachNewPriceIsRoundedToTheNearestStep(): void
    {
        // 7% of 120,000 is 8,400.
        foreach ([1 => '128400.00', 100 => '128400.00', 1000 => '128000.00', 10000 => '130000.00'] as $step => $price) {
            $after = $this->decode($this->postJson('/api/admin/plans/prices/preview', $this->change(['plan_ids' => [$this->monthly->id], 'value' => '7', 'round_to' => $step])))['changes'][0]['after'];

            self::assertSame($price, $after['price'], "to {$step}");
        }
        $after = $this->decode($this->postJson('/api/admin/plans/prices/preview', $this->change(['plan_ids' => [$this->monthly->id], 'value' => '۲٫۵', 'round_to' => 1])))['changes'][0]['after'];
        self::assertSame('123000.00', $after['price'], 'a percent with decimals, in Persian digits');
    }

    public function testMadeInOneGoAndAnsweredWithEveryPlan(): void
    {
        $response = $this->postJson('/api/admin/plans/prices', $this->change(['direction' => 'decrease', 'mode' => 'amount', 'value' => '۲۰٬۰۰۰', 'round_to' => 1, 'prices' => ['price', 'first_purchase_price', 'agent_price']]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['100000.00', '280000.00'], array_column($this->decode($response)['plans'], 'price'));
        self::assertSame(['100000.00', '30000.00', '80000.00'], [$this->monthly->refresh()->price, $this->monthly->first_purchase_price, $this->monthly->agent_price]);
        self::assertSame('280000.00', $this->quarterly->refresh()->price);
    }

    public function testAPlanThatCannotTakeItHoldsEveryPlanBack(): void
    {
        // Half off the price alone: the monthly plan's agent price would be above it.
        $change = $this->change(['direction' => 'decrease', 'value' => '50']);

        $changes = $this->decode($this->postJson('/api/admin/plans/prices/preview', $change))['changes'];
        self::assertSame(['قیمت نماینده دیگر کمتر از قیمت پلن نمی‌ماند؛ آن را هم تغییر دهید یا خالی کنید.', null], array_column($changes, 'problem'));

        $refused = $this->postJson('/api/admin/plans/prices', $change);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['plan_ids' => ['پلن «یک‌ماهه»: قیمت نماینده دیگر کمتر از قیمت پلن نمی‌ماند؛ آن را هم تغییر دهید یا خالی کنید.']], $this->decode($refused)['errors']);
        self::assertSame(['120000.00', '300000.00'], [$this->monthly->refresh()->price, $this->quarterly->refresh()->price], 'nothing changed, the plan that could take it neither');

        $below = $this->decode($this->postJson('/api/admin/plans/prices/preview', $this->change(['direction' => 'decrease', 'mode' => 'amount', 'value' => '200000', 'prices' => ['price', 'first_purchase_price', 'agent_price']])))['changes'];
        self::assertSame(['قیمت کمتر از صفر می‌شود.', null], array_column($below, 'problem'));
    }

    public function testEveryRefusalAtOnce(): void
    {
        $refused = $this->unchecked()->postJson('/api/admin/plans/prices/preview', ['plan_ids' => [], 'direction' => 'decrease', 'mode' => 'percent', 'value' => '150', 'round_to' => 5, 'prices' => []]);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame([
            'plan_ids' => ['دست‌کم یک پلن انتخاب کنید.'],
            'value' => ['درصد تغییر را عددی بیشتر از 0 و حداکثر 100 وارد کنید.'],
            'round_to' => ['گرد کردن را انتخاب کنید.'],
            'prices' => ['دست‌کم یکی از قیمت‌ها را برای تغییر انتخاب کنید.'],
        ], $this->decode($refused)['errors']);

        $amount = $this->postJson('/api/admin/plans/prices/preview', $this->change(['mode' => 'amount', 'value' => '1.5']));
        self::assertSame(['value' => ['مبلغ تغییر را به تومان و بدون اعشار، بیشتر از 0 وارد کنید.']], $this->decode($amount)['errors']);
        self::assertSame(['value' => ['درصد تغییر را عددی بیشتر از 0 و حداکثر 1000 وارد کنید.']], $this->decode($this->postJson('/api/admin/plans/prices/preview', $this->change(['value' => '0'])))['errors']);
    }

    public function testAnAgentChangesTheirOwnShopsPlansAlone(): void
    {
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): Plan => $this->plan(['name' => 'یک‌ماهه نماینده', 'price' => '90000.00'], $this->server));
        $this->loginAsAgent($bot);

        $changed = $this->decode($this->postJson('/api/agent/plans/prices', $this->change(['plan_ids' => [$theirs->id]])))['plans'];
        self::assertSame([['id' => $theirs->id, 'price' => '99000.00']], array_map(static fn(array $plan): array => ['id' => $plan['id'], 'price' => $plan['price']], $changed));

        $other = $this->postJson('/api/agent/plans/prices', $this->change(['plan_ids' => [$this->monthly->id]]));
        self::assertSame(['plan_ids' => ['یکی از پلن‌ها پیدا نشد؛ صفحه را دوباره باز کنید.']], $this->decode($other)['errors'], 'the main shop\'s plan is none of theirs');
        self::assertSame('120000.00', $this->monthly->refresh()->price);
    }

    /**
     * Both plans' prices up 10%, to the nearest thousand.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function change(array $overrides = []): array
    {
        return $overrides + [
            'plan_ids' => [$this->monthly->id, $this->quarterly->id],
            'direction' => 'increase',
            'mode' => 'percent',
            'value' => '10',
            'round_to' => 1000,
            'prices' => ['price'],
        ];
    }
}
