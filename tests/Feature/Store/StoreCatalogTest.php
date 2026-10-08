<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Http\ErrorHandler;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Catalog\Services\PlanCategoryService;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * What the shop's website sells is exactly what its bot's «خرید اشتراک» offers now — the same categories in the same
 * order, an empty one too, the plans of none (or of one switched off) last, each plan only while it can be delivered and
 * with the servers a customer may pick it on; in an agent's shop, only what the agent's traffic covers. One plan is the
 * catalogue's or a 404. How the servers stand is anyone's to read — never their addresses, connectors or errors.
 */
final class StoreCatalogTest extends HttpTestCase
{
    private Website $website;

    private Server $berlin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->website = $this->website();
        $this->berlin = $this->sellingServer('Berlin');
    }

    public function testTheCatalogIsTheBotsOfferInItsOrder(): void
    {
        $economy = $this->category(['name' => 'اقتصادی']);
        $special = $this->category(['name' => 'ویژه']);
        $retired = $this->category(['name' => 'قدیمی', 'is_active' => false]);
        $monthly = $this->plan(['name' => 'یک‌ماهه', 'category_id' => $economy->id, 'description' => 'برای یک ماه'], $this->berlin);
        $loose = $this->plan(['name' => 'بی‌دسته', 'traffic_gb' => 0, 'duration_days' => 0, 'ip_limit' => 0], $this->berlin);
        $filedAway = $this->plan(['name' => 'دسته خاموش', 'category_id' => $retired->id, 'traffic_gb' => 0.5], $this->berlin);
        $this->plan(['name' => 'خاموش', 'category_id' => $economy->id, 'is_active' => false], $this->berlin);
        $this->plan(['name' => 'بی‌سرور', 'category_id' => $economy->id]);
        $this->plan(['name' => 'سرور خاموش'], $this->fakeServer('Paris', ['is_active' => false]));

        $groups = $this->decode($this->get($this->storeApi($this->website, '/plans')))['groups'];

        self::assertSame([
            ['category' => ['id' => $economy->id, 'name' => 'اقتصادی'], 'plans' => [[
                'id' => $monthly->id,
                'name' => 'یک‌ماهه',
                'description' => 'برای یک ماه',
                'price' => '120000.00',
                'traffic_gb' => 30,
                'duration_days' => 30,
                'devices' => 1,
                'category_id' => $economy->id,
                'locations' => [['id' => $this->berlin->id, 'name' => 'Berlin']],
            ]]],
            ['category' => ['id' => $special->id, 'name' => 'ویژه'], 'plans' => []],
            ['category' => null, 'plans' => [
                $this->offered($loose, null, ['traffic_gb' => 0, 'duration_days' => 0, 'devices' => 0]),
                $this->offered($filedAway, null, ['traffic_gb' => 0.5]),
            ]],
        ], $groups, 'an empty category is offered as the bot offers it; one switched off files its plans with the rest');

        // The bot's own «خرید اشتراک», group by group.
        $bot = array_map(static fn(array $group): array => [$group['category']?->name, $group['plans']->pluck('name')->all()], $this->service(PlanCategoryService::class)->groups());
        self::assertSame($bot, array_map(static fn(array $group): array => [$group['category']['name'] ?? null, array_column($group['plans'], 'name')], $groups));
    }

    public function testWithoutCategoriesTheShopIsOneGroupOrNone(): void
    {
        self::assertSame([], $this->decode($this->get($this->storeApi($this->website, '/plans')))['groups'], 'nothing for sale');

        $plan = $this->plan([], $this->berlin);

        self::assertSame([['category' => null, 'plans' => [$this->offered($plan, null)]]], $this->decode($this->get($this->storeApi($this->website, '/plans')))['groups']);
    }

    public function testAPlansLocationsAreTheServersACustomerMayPickToday(): void
    {
        $paris = $this->sellingServer('Paris');
        $full = $this->sellingServer('Full', ['capacity' => 1]);
        $linkless = $this->sellingServer('Linkless', ['serves_subscriptions' => false]);
        $unchecked = $this->sellingServer('Unchecked', ['serves_subscriptions' => null]);
        $plan = $this->plan([], [$paris, $full, $linkless, $unchecked, $this->berlin]);
        $this->subscription($this->customer(), $plan, $full, 'ali_1');

        $offered = $this->decode($this->get($this->storeApi($this->website, "/plans/{$plan->id}")))['plan'];

        self::assertSame([['id' => $paris->id, 'name' => 'Paris'], ['id' => $this->berlin->id, 'name' => 'Berlin']], $offered['locations'], 'in the plan\'s order; a full server, one without links and one never checked are not offered');
    }

    public function testOnePlanIsTheCatalogsOrA404(): void
    {
        $economy = $this->category(['name' => 'اقتصادی']);
        $retired = $this->category(['name' => 'قدیمی', 'is_active' => false]);
        $filed = $this->plan(['category_id' => $economy->id], $this->berlin);
        $retiredOne = $this->plan(['category_id' => $retired->id], $this->berlin);

        self::assertSame(['plan' => $this->offered($filed, $economy)], $this->decode($this->get($this->storeApi($this->website, "/plans/{$filed->id}"))));
        self::assertNull($this->decode($this->get($this->storeApi($this->website, "/plans/{$retiredOne->id}")))['plan']['category_id'], 'offered with the rest, as in the catalogue');

        $off = $this->plan(['is_active' => false], $this->berlin);
        $bare = $this->plan();
        $theirs = CurrentBot::run($this->agentBot(), fn(): Plan => $this->plan(['traffic_gb' => 10], $this->berlin));
        foreach (['switched off' => $off->id, 'on no server' => $bare->id, "another shop's" => $theirs->id, 'none at all' => 999] as $case => $id) {
            $response = $this->get($this->storeApi($this->website, "/plans/{$id}"));

            self::assertSame([404, ErrorHandler::NOT_FOUND], [$response->getStatusCode(), $this->decode($response)['message']], $case);
        }
    }

    public function testAnAgentsShopOffersWhatTheirTrafficCovers(): void
    {
        $bot = $this->agentBot(traffic: 20);
        [$website, $small, $big] = CurrentBot::run($bot, fn(): array => [
            $this->website(),
            $this->plan(['name' => 'small', 'traffic_gb' => 10], $this->berlin),
            $this->plan(['name' => 'big', 'traffic_gb' => 50], $this->berlin),
        ]);
        $this->plan(['name' => 'the main shop\'s'], $this->berlin);

        $groups = $this->decode($this->get($this->storeApi($website, '/plans')))['groups'];

        self::assertSame([['category' => null, 'plans' => [$this->offered($small, null, ['name' => 'small', 'traffic_gb' => 10])]]], $groups, 'the 50 GB plan is more than their 20 GB covers');
        self::assertSame(404, $this->get($this->storeApi($website, "/plans/{$big->id}"))->getStatusCode());

        $this->traffic($bot, 60);
        self::assertSame(['small', 'big'], array_column($this->decode($this->get($this->storeApi($website, '/plans')))['groups'][0]['plans'], 'name'), 'bought more: on sale again');
    }

    public function testStatusSaysWhetherEachServerTakesACustomerAndNothingOfItsPanel(): void
    {
        $this->berlin->forceFill(['last_checked_at' => now()->subMinutes(5), 'sort' => 2])->save();
        $failing = $this->sellingServer('Failing', ['sort' => 1, 'base_url' => 'https://secret-panel.example:2053/hidden', 'last_error' => 'پنل جواب نداد (تایم‌اوت).', 'last_checked_at' => now()->subMinute()]);
        $full = $this->sellingServer('Full', ['sort' => 3, 'capacity' => 1]);
        $off = $this->sellingServer('Off', ['sort' => 4, 'is_active' => false]);
        $linkless = $this->sellingServer('Linkless', ['sort' => 5, 'serves_subscriptions' => false]);
        $this->sellingServer('Unsold', ['sort' => 0]);
        $behindASwitchedOffPlan = $this->sellingServer('Behind', ['sort' => 0]);
        $plan = $this->plan([], [$this->berlin, $failing, $full, $off]);
        $this->plan([], [$linkless, $this->berlin]);
        $this->plan(['is_active' => false], $behindASwitchedOffPlan);
        $this->subscription($this->customer(), $plan, $full, 'ali_1');

        $response = $this->get($this->storeApi($this->website, '/status'));

        self::assertSame(['servers' => [
            ['id' => $failing->id, 'name' => 'Failing', 'available' => true, 'checked_at' => '2026-10-07T11:59:00+00:00'],
            ['id' => $this->berlin->id, 'name' => 'Berlin', 'available' => true, 'checked_at' => '2026-10-07T11:55:00+00:00'],
            ['id' => $full->id, 'name' => 'Full', 'available' => false, 'checked_at' => null],
            ['id' => $off->id, 'name' => 'Off', 'available' => false, 'checked_at' => null],
            ['id' => $linkless->id, 'name' => 'Linkless', 'available' => false, 'checked_at' => null],
        ]], $this->decode($response), 'the servers of the plans on sale, once each, in their order');
        $body = (string) $response->getBody();
        foreach (['secret-panel', 'fake', 'تایم‌اوت'] as $kept) {
            self::assertStringNotContainsString($kept, $body, 'a server\'s address, its connector and its error stay in the shop');
        }
    }

    public function testAnAgentsStatusIsTheirPlansServers(): void
    {
        $paris = $this->sellingServer('Paris');
        $this->plan([], $paris);
        $bot = $this->agentBot();
        $website = CurrentBot::run($bot, function (): Website {
            $this->plan(['traffic_gb' => 10], $this->berlin);

            return $this->website();
        });

        self::assertSame([$this->berlin->id], array_column($this->decode($this->get($this->storeApi($website, '/status')))['servers'], 'id'), 'not the main shop\'s Paris');
    }

    /**
     * The plan as the catalogue offers it on Berlin alone, under `$category`, with `$overrides` over what it would say.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function offered(Plan $plan, ?PlanCategory $category, array $overrides = []): array
    {
        return array_replace([
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => null,
            'price' => '120000.00',
            'traffic_gb' => 30,
            'duration_days' => 30,
            'devices' => 1,
            'category_id' => $category?->id,
            'locations' => [['id' => $this->berlin->id, 'name' => 'Berlin']],
        ], $overrides);
    }
}
