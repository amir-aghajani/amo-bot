<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Modules\Imports\Budget;
use App\Modules\Imports\Choices;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeProvider;

/**
 * The services stage and a listing that leaves a client's device limit unsaid (ClientInfo::$ipLimit null — Rebecca's
 * list never says it): on a panel whose connector keeps a device limit, that client is read on its own (findClient())
 * and comes with the limit it has; a client the listing gives its limit, or one on a panel that keeps none, comes from
 * the listing as it is — and so does one whose own read fails, the import going on.
 */
final class ServicesStageIpLimitTest extends ImportTestCase
{
    private Server $server;

    /** @var list<string> The calls the panel was asked, in order. */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 12:00:00');
        $this->fakePanel();
        $this->server = $this->fakeServer('آلمان');
        $this->migrator->panels = [self::fakePanelOf('p1', 'آلمان', 1)];
        $this->migrator->customers = [new ImportedCustomer('c1', 1001)];
        $this->migrator->services = [new ImportedService('s1', 'c1', 'p1', 'amir_1')];
        FakeProvider::$onCall = function (int $server, string $call): void {
            $this->calls[] = $call;
        };
    }

    public function testAListedClientWithoutItsDeviceLimitIsReadOnItsOwnAndComesWithIt(): void
    {
        $this->listedAs(null, readAs: 3);

        $this->importAll($this->onServer());

        self::assertSame(['listClients', 'findClient'], $this->calls);
        self::assertSame(3, (int) Subscription::query()->sole()->ip_limit, 'the limit its own read says');
    }

    public function testAListedClientWithItsDeviceLimitComesFromTheListing(): void
    {
        $this->listedAs(2, readAs: 5);

        $this->importAll($this->onServer());

        self::assertSame(['listClients'], $this->calls, 'not read again');
        self::assertSame(2, (int) Subscription::query()->sole()->ip_limit);
    }

    public function testOnAPanelThatKeepsNoDeviceLimitTheListingIsAllThereIs(): void
    {
        FakeProvider::$capabilities = new Capabilities(inbounds: true, linkRotation: true, ipLimit: false);
        $this->listedAs(null, readAs: 3);

        $this->importAll($this->onServer());

        self::assertSame(['listClients'], $this->calls, 'nothing more to learn from its own read');
        self::assertSame(0, (int) Subscription::query()->sole()->ip_limit);
    }

    public function testAClientGoneBeforeItsOwnReadIsNotOnThePanel(): void
    {
        FakeProvider::put($this->server, new ClientInfo('amir_1', true, subscriptionUrl: 'https://fake.test/sub/aaa'));
        FakeProvider::$whileListing = function (): void {
            unset(FakeProvider::$clients[$this->server->id]['amir_1']);
        };

        $import = $this->importAll($this->onServer());

        self::assertSame(['listClients', 'findClient'], $this->calls);
        self::assertSame(0, Subscription::query()->count());
        self::assertSame(['not_on_panel'], array_column(self::problemRows($import), 'reason'));
    }

    public function testAListedClientWhoseOwnReadFailsComesAsListedAndTheImportGoesOn(): void
    {
        FakeProvider::put($this->server, new ClientInfo('amir_1', true, subscriptionUrl: 'https://fake.test/sub/aaa'));
        FakeProvider::$failing[$this->server->id]['findClient'] = ConnectionFailure::Timeout;

        $import = $this->started($this->onServer());
        for ($step = 0; $step < 5 && $import->phase === ImportPhase::Importing; $step++) {
            $this->step($import, Budget::unlimited());
        }

        self::assertSame(ImportPhase::Done, $import->phase, 'never stuck: the listing answered for the client, each step afresh');
        self::assertSame(['listClients', 'findClient'], $this->calls, 'its own read tried once');
        self::assertSame(['amir_1', 0], [Subscription::query()->sole()->remote_name, (int) Subscription::query()->sole()->ip_limit], 'as the listing has it, its device limit unknown');
        self::assertSame([], self::problemRows($import));
    }

    /** The client the listing gives (device limit `$listed`), and the one its own read gives after it (`$readAs`). */
    private function listedAs(?int $listed, int $readAs): void
    {
        FakeProvider::put($this->server, new ClientInfo('amir_1', true, subscriptionUrl: 'https://fake.test/sub/aaa', ipLimit: $listed));
        FakeProvider::$whileListing = function () use ($readAs): void {
            FakeProvider::put($this->server, new ClientInfo('amir_1', true, subscriptionUrl: 'https://fake.test/sub/aaa', ipLimit: $readAs));
        };
    }

    /** @return array<string, mixed> The choices that find panel p1's services on the test's server. */
    private function onServer(): array
    {
        return ['panels' => ['p1' => ['action' => Choices::SERVER, 'server_id' => $this->server->id]]];
    }
}
