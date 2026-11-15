<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Modules\Accounts\Services\AccountMerger;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Choices;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Enums\ImportStage;
use App\Modules\Imports\Links;
use App\Modules\Imports\Models\ImportLink;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeProvider;

/**
 * The services stage: each service found by its client's name on the server its panel maps to and taken into the shop
 * as the panel has it — with its plan when it had one, as a claimed service when not —, nothing on the panel changed;
 * and every way a service stays behind: not chosen, its panel skipped or silent, its customer not brought, its client
 * gone or without a link, the client the shop's already.
 */
final class ServicesStageTest extends ImportTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 12:00:00');
        $this->fakePanel();
        $this->server = $this->fakeServer('آلمان');
        $this->migrator->panels = [self::fakePanelOf('p1', 'آلمان', 3)];
        $this->migrator->customers = [new ImportedCustomer('c1', 1001), new ImportedCustomer('c2', 2002)];
    }

    public function testAServiceIsTakenInAsItsPanelHasItWithItsPlan(): void
    {
        $this->client('amir_1', 'https://fake.test/sub/aaa', deadline: '2026-12-13 12:00:00', total: 30 * 1024 ** 3, used: 1024 ** 3);
        $this->migrator->plans = [new ImportedPlan('pl', 'یک‌ماهه', null, '100000', null, '30', 30)];
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'amir_1', planKey: 'pl', label: 'گوشی من که اسمش خیلی طولانی است و جا نمی‌شود')];

        $import = $this->importAll($this->onServer());

        $service = Subscription::query()->sole();
        $user = User::query()->where('telegram_id', 1001)->firstOrFail();
        self::assertSame([$user->id, $this->server->id, 'amir_1', 'https://fake.test/sub/aaa', SubscriptionStatus::Active], [$service->user_id, $service->server_id, $service->remote_name, $service->subscription_url, $service->status]);
        self::assertSame([30 * 1024 ** 3, 720], [$service->traffic_limit_bytes, $service->duration_hours], 'the panel\'s quota, and the hours left to its deadline');
        self::assertNotNull($service->plan_id);
        self::assertNull($service->claimed_at, 'sold from a plan: no claimed service');
        self::assertFalse($service->is_test);
        self::assertSame(32, mb_strlen((string) $service->label));
        self::assertSame([], FakeProvider::$updated, 'nothing on the panel changes, not even its comment');
        self::assertSame(['created' => 1, 'linked' => 0, 'skipped' => 0], self::counts($import)['services']);
        self::assertSame($service->id, ImportLink::query()->where(['kind' => Links::SERVICE, 'source_key' => 's1'])->value('target_id'));
    }

    public function testAServiceWithoutAPlanComesAsAClaimedOneAndATestAsATest(): void
    {
        $this->client('free_1', 'https://fake.test/sub/bbb');
        $this->client('test_1', 'https://fake.test/sub/ccc');
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'free_1', planKey: 'unknown'), new ImportedService('s2', 'c2', 'p1', 'test_1', test: true)];

        $this->importAll($this->onServer());

        $free = Subscription::query()->where('remote_name', 'free_1')->firstOrFail();
        self::assertSame([null, '2026-11-13 12:00:00'], [$free->plan_id, $free->claimed_at?->format('Y-m-d H:i:s')]);
        self::assertTrue(Subscription::query()->where('remote_name', 'test_1')->firstOrFail()->is_test);
    }

    public function testServicesAndTestsComeOnlyWhenChosen(): void
    {
        $this->client('paid_1', 'https://fake.test/sub/a');
        $this->client('test_1', 'https://fake.test/sub/b');
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'paid_1'), new ImportedService('s2', 'c2', 'p1', 'test_1', test: true)];

        $import = $this->importAll($this->onServer() + ['tests' => false]);

        self::assertSame(['paid_1'], Subscription::query()->pluck('remote_name')->all());
        self::assertSame(['not_chosen' => 1], array_column($this->imports()->present($import)['problems']['by_reason'], 'count', 'reason'));
    }

    public function testNoTestComesWhileTheServicesDoNot(): void
    {
        $this->client('test_1', 'https://fake.test/sub/b');
        $this->migrator->services = [new ImportedService('s2', 'c2', 'p1', 'test_1', test: true)];

        $import = $this->importAll($this->onServer() + ['services' => false, 'tests' => true]);

        self::assertSame(0, Subscription::query()->count());
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 0], self::counts($import)['services'], 'the stage passed over');
    }

    public function testAServiceStaysBehindForEachReasonItCannotCome(): void
    {
        $this->client('no_link', null);
        $theirs = $this->customer(['telegram_id' => 7007]);
        $this->subscription($theirs, $this->plan(), $this->server, 'someone_elses');
        FakeProvider::put($this->server, new ClientInfo('someone_elses', true, subscriptionUrl: 'https://fake.test/sub/x'));
        $this->migrator->services = [
            new ImportedService('s1', 'nobody', 'p1', 'whoever'),
            new ImportedService('s2', 'c1', 'p1', 'gone_from_panel'),
            new ImportedService('s3', 'c1', 'p1', 'no_link'),
            new ImportedService('s4', 'c2', 'p1', 'someone_elses'),
            new ImportedService('s5', 'c1', 'p1', 'resold', reseller: 'shop_bot'),
            new ImportedService('s6', 'c1', 'p1', ''),
        ];

        $import = $this->importAll($this->onServer());

        self::assertSame(1, Subscription::query()->count(), 'only the one that was there');
        self::assertSame([
            ['s1', 'no_customer', "\u{2068}whoever\u{2069} — آلمان"],
            ['s2', 'not_on_panel', "\u{2068}gone_from_panel\u{2069} — آلمان"],
            ['s3', 'no_link', "\u{2068}no_link\u{2069} — آلمان"],
            ['s4', 'kept_already', "\u{2068}someone_elses\u{2069} — آلمان (#" . Subscription::query()->value('id') . ')'],
            ['s6', 'unreadable', null],
        ], array_map(static fn(array $row): array => [$row['key'], $row['reason'], $row['detail']], self::problemRows($import)));
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 6], self::counts($import)['services']);
    }

    public function testTheCustomersOwnServiceTheShopKeepsAlreadyIsLinked(): void
    {
        $this->migrator->customers = [new ImportedCustomer('c1', self::TELEGRAM_ID)];
        $mine = $this->subscription($this->customer(), $this->plan(), $this->server, 'mine_1');
        FakeProvider::put($this->server, new ClientInfo('mine_1', true, subscriptionUrl: 'https://fake.test/sub/m'));
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'mine_1')];

        $import = $this->importAll($this->onServer());

        self::assertSame(1, Subscription::query()->count());
        self::assertSame(['created' => 0, 'linked' => 1, 'skipped' => 0], self::counts($import)['services']);
        self::assertSame($mine->id, ImportLink::query()->where(['kind' => Links::SERVICE, 'source_key' => 's1'])->value('target_id'));
    }

    public function testAPanelSkippedLeavesItsServicesBehind(): void
    {
        $this->client('amir_1', 'https://fake.test/sub/a');
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'amir_1')];

        $import = $this->importAll();

        self::assertSame(0, Subscription::query()->count());
        self::assertSame(['panel_skipped' => 1], array_column($this->imports()->present($import)['problems']['by_reason'], 'count', 'reason'));
    }

    public function testAPanelThatDoesNotAnswerIsAskedAgainAFewStepsThenItsServicesComeWithALaterImport(): void
    {
        FakeProvider::$down = [$this->server->id];
        $this->client('amir_1', 'https://fake.test/sub/a');
        $this->client('amir_2', 'https://fake.test/sub/b');
        $calls = 0;
        FakeProvider::$onCall = static function (int $server, string $call) use (&$calls): void {
            $calls++;
        };
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'amir_1'), new ImportedService('s2', 'c1', 'p1', 'amir_2')];

        $import = $this->started($this->onServer());
        $stops = [];
        for ($steps = 0; $import->phase === ImportPhase::Importing; $steps++) {
            self::assertLessThan(10, $steps, 'it ends');
            $import = $this->step($import, Budget::unlimited());
            $stops[] = [$import->stage?->value, $import->cursor];
        }

        self::assertSame([['services', 0], ['services', 0], ['services', 0], [null, 0]], $stops, 'each silent step stops before the service, its cursor where it was; the fourth gives up');
        self::assertSame(4, $calls, 'one listing a step, not one per service');
        self::assertSame(0, Subscription::query()->count());
        self::assertSame(['panel_unreachable', 'panel_unreachable'], array_column(self::problemRows($import), 'reason'));
        self::assertStringStartsWith("\u{2068}amir_1\u{2069} — آلمان: ", (string) self::problemRows($import)[0]['detail']);
        self::assertNotNull($this->server->refresh()->last_error, 'the contact recorded on the server');

        FakeProvider::$down = [];
        $later = $this->importAll($this->onServer());
        self::assertSame(2, Subscription::query()->count());
        self::assertSame(['created' => 2, 'linked' => 0, 'skipped' => 0], self::counts($later)['services']);
    }

    public function testAPanelThatAnswersAgainAtTheNextStepBringsItsServicesInTheSameImport(): void
    {
        FakeProvider::$down = [$this->server->id];
        $this->client('amir_1', 'https://fake.test/sub/a');
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'amir_1')];

        $import = $this->step($this->started($this->onServer()), Budget::unlimited());
        self::assertSame([ImportPhase::Importing, ImportStage::Services, 0], [$import->phase, $import->stage, $import->cursor]);

        FakeProvider::$down = [];
        $import = $this->step($import, Budget::unlimited());

        self::assertSame(ImportPhase::Done, $import->phase);
        self::assertSame(['amir_1'], Subscription::query()->pluck('remote_name')->all());
        self::assertSame([], self::problemRows($import));
    }

    public function testAServiceDeletedFromTheShopSinceIsNotTakenInAgain(): void
    {
        $this->client('amir_1', 'https://fake.test/sub/a');
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'amir_1')];
        $this->importAll($this->onServer());
        Subscription::query()->delete();

        $again = $this->importAll($this->onServer());

        self::assertSame([], Subscription::query()->pluck('remote_name')->all(), 'the owner removed it from the shop: brought once');
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 1], self::counts($again)['services']);
    }

    public function testTheServicesOfACustomerDeletedSinceStayBehindCountedAsRemoved(): void
    {
        $this->importAll();
        User::query()->where('telegram_id', 1001)->delete();
        $this->client('amir_1', 'https://fake.test/sub/a');
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'amir_1'), new ImportedService('s2', 'nobody', 'p1', 'amir_1')];

        $again = $this->importAll($this->onServer());

        self::assertSame(0, Subscription::query()->count());
        self::assertSame([['s2', 'no_customer']], array_map(static fn(array $row): array => [$row['key'], $row['reason']], self::problemRows($again)), 'the owner removed their customer: only counted');
        self::assertSame(['removed' => 2, 'no_customer' => 1], array_column($this->imports()->present($again)['problems']['by_reason'], 'count', 'reason'), 'the customer, and their service');
    }

    public function testAServiceOfACustomerMergedSinceComesToTheAccountThatStayed(): void
    {
        $this->telegram();
        Carbon::setTestNow('2026-10-01 10:00:00');
        $web = $this->webCustomer();
        Carbon::setTestNow('2026-11-13 12:00:00');
        $this->importAll();
        $survivor = $this->service(AccountMerger::class)->merge($web->refresh(), User::query()->where('telegram_id', 1001)->sole(), AccountMerger::BY_CUSTOMER);
        $this->client('amir_1', 'https://fake.test/sub/a');
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'amir_1')];

        $again = $this->importAll($this->onServer());

        self::assertSame($survivor->id, Subscription::query()->sole()->user_id);
        self::assertSame(['created' => 1, 'linked' => 0, 'skipped' => 0], self::counts($again)['services']);
    }

    public function testAPanelThatCannotListItsClientsIsAskedForEach(): void
    {
        FakeProvider::$lists = false;
        $this->client('amir_1', 'https://fake.test/sub/a');
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'amir_1'), new ImportedService('s2', 'c1', 'p1', 'missing')];

        $import = $this->importAll($this->onServer());

        self::assertSame(['amir_1'], Subscription::query()->pluck('remote_name')->all());
        self::assertSame(['not_on_panel'], array_column(self::problemRows($import), 'reason'));
    }

    public function testAPanelAskedForEachThatAnswersAgainHasItsTriesAfresh(): void
    {
        FakeProvider::$lists = false;
        $names = ['amir_1', 'amir_2', 'amir_3', 'amir_4'];
        foreach ($names as $at => $name) {
            $this->client($name, "https://fake.test/sub/{$at}");
        }
        $this->migrator->services = array_map(static fn(string $name): ImportedService => new ImportedService("s-{$name}", 'c1', 'p1', $name), $names);
        // Every other read times out: each service's first, the step stopping before it; its second answers.
        $reads = 0;
        FakeProvider::$onCall = function (int $server, string $call) use (&$reads): void {
            if ($call === 'findClient') {
                FakeProvider::$failing[$server] = ++$reads % 2 === 1 ? ['findClient' => ConnectionFailure::Timeout] : [];
            }
        };

        $import = $this->started($this->onServer());
        for ($steps = 0; $import->phase === ImportPhase::Importing; $steps++) {
            self::assertLessThan(10, $steps, 'it ends');
            $import = $this->step($import, Budget::unlimited());
        }

        self::assertSame($names, Subscription::query()->orderBy('id')->pluck('remote_name')->all(), 'a silence in a row counts, not every one of the import: the panel answered between them');
        self::assertSame([], self::problemRows($import));
    }

    public function testAnEndedClientComesEndedToBeRenewed(): void
    {
        $this->client('old_1', 'https://fake.test/sub/o', deadline: '2026-11-01 00:00:00', enabled: false);
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'old_1')];

        $this->importAll($this->onServer());

        self::assertSame(SubscriptionStatus::Expired, Subscription::query()->sole()->status);
    }

    /** @return array<string, mixed> The choices that find panel p1's services on the test's server. */
    private function onServer(): array
    {
        return ['panels' => ['p1' => ['action' => Choices::SERVER, 'server_id' => $this->server->id]]];
    }

    private function client(string $name, ?string $link, ?string $deadline = null, int $total = 0, int $used = 0, bool $enabled = true): void
    {
        FakeProvider::put($this->server, new ClientInfo(
            name: $name,
            enabled: $enabled,
            downloadBytes: $used,
            totalBytes: $total,
            expiry: $deadline === null ? Expiry::never() : Expiry::at(new \DateTimeImmutable($deadline)),
            subscriptionUrl: $link,
        ));
    }
}
