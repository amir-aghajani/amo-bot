<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Catalog\Models\PlanServer;
use App\Modules\Imports\Choices;
use App\Modules\Imports\Links;
use App\Modules\Imports\Models\ImportLink;
use App\Modules\Imports\Records\ImportedPanel;
use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Providers\Models\Server;

/**
 * The servers and plans stages: a panel the owner chose to add made a server through the servers' own form and first
 * check — one that does not answer is not kept, its services left behind —, and the plans made through the plans' form
 * on the servers their panels map to, in their categories, switched on only when the owner says so — and never one only
 * agents saw or the other bot no longer sold.
 */
final class CatalogStageTest extends ImportTestCase
{
    /** A 3x-ui panel's connection as its server form takes it. */
    private const CONNECTION = ['base_url' => 'https://panel.example:2053/base/', 'auth_mode' => 'token', 'api_token' => 'tok-1', 'verify_tls' => true, 'timeout' => 30, 'proxy_enabled' => false];

    public function testAPanelChosenToBeAddedBecomesAServerOnceThroughTheServersForm(): void
    {
        $this->panelHttp()->healthyPanel();
        $this->migrator->panels = [new ImportedPanel('de', 'آلمان ۱', 'x-ui_single', 'https://panel.example:2053', '3x-ui', null, self::CONNECTION, 0)];

        $import = $this->importAll(['panels' => ['de' => ['action' => Choices::ADD]]]);

        $server = Server::query()->sole();
        self::assertSame(['آلمان ۱', '3x-ui', 'https://panel.example:2053/base', 'tok-1'], [$server->name, $server->driver, $server->base_url, $server->api_token]);
        self::assertNotNull($server->last_checked_at, 'checked as the servers screen checks a new one');
        self::assertSame(2, $server->inbounds()->count());
        self::assertSame(['created' => 1, 'linked' => 0, 'skipped' => 0], self::counts($import)['servers']);

        $again = $this->importAll(['panels' => ['de' => ['action' => Choices::ADD]]]);
        self::assertSame(1, Server::query()->count(), 'the next import finds it again');
        self::assertSame(['created' => 0, 'linked' => 1, 'skipped' => 0], self::counts($again)['servers']);
    }

    public function testAPanelClosedToNewSalesComesSwitchedOffWithItsCapacity(): void
    {
        $this->panelHttp()->healthyPanel();
        $this->migrator->panels = [new ImportedPanel('de', 'آلمان', 'x-ui_single', 'https://panel.example:2053', '3x-ui', null, self::CONNECTION, 0, active: false, capacity: 150)];

        $this->importAll(['panels' => ['de' => ['action' => Choices::ADD]]]);

        $server = Server::query()->sole();
        self::assertSame([false, 150], [$server->is_active, $server->capacity], 'it takes no new sales here either');
    }

    public function testAServerIsKnownByItsPanelsAddressNotItsName(): void
    {
        $this->panelHttp()->healthyPanel();
        $this->migrator->panels = [new ImportedPanel('de', 'آلمان', 'x-ui_single', 'https://panel.example:2053/base/', '3x-ui', null, self::CONNECTION, 0)];
        $this->importAll(['panels' => ['de' => ['action' => Choices::ADD]]]);

        // The same panel named otherwise: the same server.
        $this->migrator->panels = [new ImportedPanel('Germany', 'Germany', 'x-ui_single', 'https://PANEL.example:2053/base', '3x-ui', null, self::CONNECTION, 0)];
        $renamed = $this->importAll(['panels' => ['Germany' => ['action' => Choices::ADD]]]);
        self::assertSame(1, Server::query()->count());
        self::assertSame(['created' => 0, 'linked' => 1, 'skipped' => 0], self::counts($renamed)['servers']);
        self::assertSame(['https://panel.example:2053/base'], ImportLink::query()->where('kind', Links::SERVER)->pluck('source_key')->all());

        // Another panel of the same name: a server of its own.
        $this->panelHttp()->healthyPanel();
        $this->migrator->panels = [new ImportedPanel('de', 'آلمان', 'x-ui_single', 'https://other.example:2053/base/', '3x-ui', null, ['base_url' => 'https://other.example:2053/base/'] + self::CONNECTION, 0)];
        $other = $this->importAll(['panels' => ['de' => ['action' => Choices::ADD]]]);
        self::assertSame(2, Server::query()->count());
        self::assertSame(['created' => 1, 'linked' => 0, 'skipped' => 0], self::counts($other)['servers']);
    }

    public function testAServerDeletedSinceIsAddedAgain(): void
    {
        $this->panelHttp()->healthyPanel();
        $this->migrator->panels = [new ImportedPanel('de', 'آلمان', 'x-ui_single', 'https://panel.example:2053', '3x-ui', null, self::CONNECTION, 0)];
        $this->importAll(['panels' => ['de' => ['action' => Choices::ADD]]]);
        Server::query()->sole()->delete();

        $this->panelHttp()->healthyPanel();
        $again = $this->importAll(['panels' => ['de' => ['action' => Choices::ADD]]]);

        self::assertSame(1, Server::query()->count());
        self::assertSame(['created' => 1, 'linked' => 0, 'skipped' => 0], self::counts($again)['servers']);
        self::assertSame(Server::query()->value('id'), ImportLink::query()->where('kind', Links::SERVER)->sole()->target_id);
    }

    public function testAPanelThatDoesNotAnswerIsNotAddedAndItsServicesStayBehind(): void
    {
        $this->panelHttp()->fail('unauthorized', 401);
        $this->migrator->panels = [new ImportedPanel('de', 'آلمان', 'x-ui_single', 'https://panel.example:2053', '3x-ui', null, self::CONNECTION, 1)];
        $this->migrator->services = [new ImportedService('s1', 'c1', 'de', 'amir_1')];

        $import = $this->importAll(['panels' => ['de' => ['action' => Choices::ADD]]]);

        self::assertSame(0, Server::query()->count());
        self::assertSame(0, ImportLink::query()->where('kind', Links::SERVER)->count(), 'nor its link: gone together');
        self::assertSame('server_not_added', self::problemRows($import)[0]['reason']);
        self::assertStringContainsString('توکن', (string) self::problemRows($import)[0]['detail']);
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 1], self::counts($import)['services']);
        self::assertSame(1, array_column($this->imports()->present($import)['problems']['by_reason'], 'count', 'reason')['panel_skipped'] ?? 0);
    }

    public function testAPanelTheServersFormRefusesIsNotAdded(): void
    {
        $this->migrator->panels = [new ImportedPanel('de', 'آلمان', 'x-ui_single', 'https://panel.example:2053', '3x-ui', null, ['base_url' => 'not an address', 'auth_mode' => 'token', 'api_token' => 'tok-1'], 0)];

        $import = $this->importAll(['panels' => ['de' => ['action' => Choices::ADD]]]);

        self::assertSame(0, Server::query()->count());
        self::assertSame(['kind' => 'servers', 'key' => 'de', 'reason' => 'server_not_added'], array_diff_key(self::problemRows($import)[0], ['detail' => true]));
        self::assertSame([], $this->panelHttp()->calls(), 'nothing asked of a panel the form refused');
    }

    public function testPlansAreMadeOnTheServersTheirPanelsMapToInTheirCategories(): void
    {
        $this->fakePanel();
        $de = $this->fakeServer('آلمان');
        $nl = $this->fakeServer('هلند');
        $this->migrator->panels = [self::fakePanelOf('p-de', 'آلمان'), self::fakePanelOf('p-nl', 'هلند'), self::fakePanelOf('p-off', 'خاموش')];
        $this->migrator->plans = [
            new ImportedPlan('10', 'یک‌ماهه ۳۰ گیگ', 'ماهانه', '120000', '100000', '30', 30, ipLimit: 2, note: 'فقط ایرانسل', panelKeys: ['p-de']),
            new ImportedPlan('11', 'همه جا', 'ماهانه', '150000.70', '200000', '0', 0, panelKeys: null),
        ];

        $import = $this->importAll(['panels' => ['p-de' => ['action' => Choices::SERVER, 'server_id' => $de->id], 'p-nl' => ['action' => Choices::SERVER, 'server_id' => $nl->id]]]);

        $category = PlanCategory::query()->sole();
        self::assertSame(['ماهانه', false], [$category->name, $category->is_active], 'made switched off, as the plans are');
        $monthly = Plan::query()->where('name', 'یک‌ماهه ۳۰ گیگ')->firstOrFail();
        self::assertSame([$category->id, '120000.00', '100000.00', 720, 30.0, 2, 'فقط ایرانسل', false], [$monthly->category_id, $monthly->price, $monthly->agent_price, $monthly->duration_hours, $monthly->traffic_gb, $monthly->ip_limit, $monthly->checkout_note, $monthly->is_active]);
        self::assertSame([[$de->id, true]], $monthly->servers()->get()->map(static fn(PlanServer $entry): array => [$entry->server_id, $entry->all_inbounds])->all());
        $everywhere = Plan::query()->where('name', 'همه جا')->firstOrFail();
        self::assertSame(['150000.00', null, 0], [$everywhere->price, $everywhere->agent_price, $everywhere->duration_hours], 'whole Toman; an agent price above the price is none');
        self::assertSame([$de->id, $nl->id], $everywhere->servers()->orderBy('sort')->pluck('server_id')->all(), 'every server a panel maps to');
        self::assertSame(['created' => 2, 'linked' => 0, 'skipped' => 0], self::counts($import)['plans']);
        self::assertSame(['created' => 1, 'linked' => 0, 'skipped' => 0], self::counts($import)['categories']);
        self::assertSame([], self::problemRows($import));
    }

    public function testPlansComeSwitchedOnOnlyWhenTheOwnerSaysButNeverAnAgentsOnlyOne(): void
    {
        $this->fakePanel();
        $de = $this->fakeServer('آلمان');
        $this->migrator->panels = [self::fakePanelOf('p-de')];
        $this->migrator->plans = [
            new ImportedPlan('1', 'عمومی', 'ماهانه', '100000', null, '10', 30),
            new ImportedPlan('2', 'نماینده', null, '80000', null, '10', 30, agentsOnly: true),
        ];

        $import = $this->importAll(['plans_on' => true, 'panels' => ['p-de' => ['action' => Choices::SERVER, 'server_id' => $de->id]]]);

        self::assertTrue(Plan::query()->where('name', 'عمومی')->value('is_active'));
        self::assertTrue(PlanCategory::query()->where('name', 'ماهانه')->value('is_active'));
        self::assertFalse(Plan::query()->where('name', 'نماینده')->value('is_active'));
        self::assertSame([['kind' => 'plans', 'key' => '2', 'reason' => 'agents_only_plan', 'detail' => 'نماینده']], self::problemRows($import));
    }

    public function testAPlanTheOtherBotNoLongerSoldComesSwitchedOffWhateverTheOwnerSays(): void
    {
        $this->fakePanel();
        $de = $this->fakeServer('آلمان');
        $this->migrator->panels = [self::fakePanelOf('p-de')];
        $this->migrator->plans = [
            new ImportedPlan('1', 'موجود', 'ماهانه', '100000', null, '10', 30),
            new ImportedPlan('2', 'ناموجود', 'سه ماهه', '250000', null, '30', 90, selling: false),
        ];

        $import = $this->importAll(['plans_on' => true, 'panels' => ['p-de' => ['action' => Choices::SERVER, 'server_id' => $de->id]]]);

        self::assertTrue(Plan::query()->where('name', 'موجود')->value('is_active'));
        $soldOut = Plan::query()->where('name', 'ناموجود')->firstOrFail();
        self::assertFalse($soldOut->is_active, 'sold out there: off here, for the owner to switch on');
        self::assertSame([$de->id], $soldOut->servers()->pluck('server_id')->all(), 'on its server all the same');
        self::assertFalse(PlanCategory::query()->where('name', 'سه ماهه')->value('is_active'), 'its new category off with it');
        self::assertSame(['created' => 2, 'linked' => 0, 'skipped' => 0], self::counts($import)['plans']);
    }

    public function testAPlanWithNoServerStaysOffAndItsNewCategoryComesOnOnlyWithAPlanSwitchedOn(): void
    {
        $this->fakePanel();
        $de = $this->fakeServer('آلمان');
        $this->migrator->panels = [self::fakePanelOf('p-de'), self::fakePanelOf('p-gone')];
        $this->migrator->plans = [
            new ImportedPlan('1', 'بی‌سرور', 'ماهانه', '100000', null, '10', 30, panelKeys: ['p-gone']),
            new ImportedPlan('2', 'با سرور', 'ماهانه', '100000', null, '10', 30, panelKeys: ['p-de']),
        ];
        $category = null;
        $this->migrator->beforeEach = static function (int $seq) use (&$category): void {
            // Before the second plan: the first one's new category as it came.
            if ($seq === 2) {
                $category = PlanCategory::query()->where('name', 'ماهانه')->value('is_active');
            }
        };

        $this->importAll(['plans_on' => true, 'panels' => ['p-de' => ['action' => Choices::SERVER, 'server_id' => $de->id]]]);

        self::assertFalse(Plan::query()->where('name', 'بی‌سرور')->value('is_active'), 'nothing to sell it on: off');
        self::assertFalse($category, 'its category came off with it');
        self::assertTrue(Plan::query()->where('name', 'با سرور')->value('is_active'));
        self::assertTrue(PlanCategory::query()->where('name', 'ماهانه')->value('is_active'), 'on once a plan of it came on');
    }

    public function testACategoryOfTheShopsStaysAsTheOwnerKeepsIt(): void
    {
        $this->fakePanel();
        $de = $this->fakeServer('آلمان');
        $this->category(['name' => 'ماهانه', 'is_active' => false]);
        $this->migrator->panels = [self::fakePanelOf('p-de')];
        $this->migrator->plans = [new ImportedPlan('1', 'یک‌ماهه', 'ماهانه', '100000', null, '10', 30)];

        $this->importAll(['plans_on' => true, 'panels' => ['p-de' => ['action' => Choices::SERVER, 'server_id' => $de->id]]]);

        self::assertFalse(PlanCategory::query()->where('name', 'ماهانه')->value('is_active'), 'the owner switched it off: off');
    }

    public function testAPlanItsFormRefusesLeavesNoCategoryBehind(): void
    {
        $this->fakePanel();
        $de = $this->fakeServer('آلمان');
        $this->migrator->panels = [self::fakePanelOf('p-de')];
        $this->migrator->plans = [new ImportedPlan('1', '<tg-emoji emoji-id="1">⭐</tg-emoji> ویژه', 'ویژه‌ها', '100000', null, '10', 30)];

        $import = $this->importAll(['panels' => ['p-de' => ['action' => Choices::SERVER, 'server_id' => $de->id]]]);

        self::assertSame([0, 0], [Plan::query()->count(), PlanCategory::query()->count()]);
        self::assertSame(['1', 'refused'], [self::problemRows($import)[0]['key'], self::problemRows($import)[0]['reason']]);
    }

    public function testAPlanDeletedSinceStaysDeleted(): void
    {
        $this->migrator->plans = [new ImportedPlan('1', 'یک‌ماهه', 'ماهانه', '100000', null, '10', 30)];
        $this->importAll();
        Plan::query()->delete();
        PlanCategory::query()->delete();

        $again = $this->importAll();

        self::assertSame([0, 0], [Plan::query()->count(), PlanCategory::query()->count()], 'the owner removed it: brought once, never made again');
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 1], self::counts($again)['plans']);
    }

    public function testANewPlanInACategoryDeletedSinceMakesTheCategoryAgain(): void
    {
        $this->migrator->plans = [new ImportedPlan('1', 'یک‌ماهه', 'ماهانه', '100000', null, '10', 30)];
        $this->importAll();
        PlanCategory::query()->delete();
        $this->migrator->plans[] = new ImportedPlan('2', 'سه‌ماهه', 'ماهانه', '250000', null, '30', 90);

        $this->importAll();

        self::assertSame(PlanCategory::query()->where('name', 'ماهانه')->value('id'), Plan::query()->where('name', 'سه‌ماهه')->value('category_id'), 'a plan brought now cannot be without its category');
    }

    public function testAPlanNoneOfWhosePanelsCameOverIsMadeWithoutAServer(): void
    {
        $this->migrator->panels = [self::fakePanelOf('p-de')];
        $this->migrator->plans = [new ImportedPlan('1', str_repeat('ن', 140), 'ماهانه', '100000', null, '999999', 9999, ipLimit: 5000)];

        $import = $this->importAll();

        $plan = Plan::query()->sole();
        self::assertSame([128, 0, 3650 * 24, 100000.0, 1000, false], [mb_strlen($plan->name), $plan->servers()->count(), $plan->duration_hours, $plan->traffic_gb, $plan->ip_limit, $plan->is_active], 'held to the plan form\'s bounds');
        self::assertSame('plan_no_server', self::problemRows($import)[0]['reason']);
        self::assertSame($plan->id, ImportLink::query()->where(['kind' => Links::PLAN, 'source_key' => '1'])->value('target_id'));
    }

    public function testACategoryTheShopHasIsUsedAndAPlanIsMadeOnce(): void
    {
        $this->fakePanel();
        $de = $this->fakeServer('آلمان');
        $mine = $this->category(['name' => 'ماهانه']);
        $this->migrator->panels = [self::fakePanelOf('p-de')];
        $this->migrator->plans = [new ImportedPlan('1', 'یک‌ماهه', 'ماهانه', '100000', null, '10', 30)];
        $choices = ['panels' => ['p-de' => ['action' => Choices::SERVER, 'server_id' => $de->id]]];

        $import = $this->importAll($choices);
        $again = $this->importAll($choices);

        self::assertSame($mine->id, Plan::query()->sole()->category_id);
        self::assertSame(['created' => 0, 'linked' => 1, 'skipped' => 0], self::counts($import)['categories']);
        self::assertSame(['created' => 0, 'linked' => 1, 'skipped' => 0], self::counts($again)['plans']);
    }

    public function testWithoutPlansNoneIsMade(): void
    {
        $this->migrator->plans = [new ImportedPlan('1', 'یک‌ماهه', 'ماهانه', '100000', null, '10', 30)];

        $this->importAll(['plans' => false]);

        self::assertSame(0, Plan::query()->count());
        self::assertSame(0, PlanCategory::query()->count());
    }
}
