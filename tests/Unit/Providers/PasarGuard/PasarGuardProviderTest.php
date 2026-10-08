<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\PasarGuard;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\Drivers\PasarGuard\PasarGuardDriver;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\CoreState;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * The generic ProviderInterface on PasarGuard: users for clients, groups for inbounds, on hold for a term
 * that waits for the first connection, and the link the panel signs made absolute.
 */
final class PasarGuardProviderTest extends TestCase
{
    private const KEY = 'pg_key_6f1c2a3b-4d5e-4f60-8a1b-2c3d4e5f6a7b';
    private const MONTH = 30 * 86400;

    private FakePanel $panel;

    public function testANewClientIsAUserOnHoldInTheEntrysGroupsWithItsLinkMadeAbsolute(): void
    {
        $provider = $this->provider([self::json(201, self::user())]);

        $client = $provider->createClient(['3', '5', '3'], new ClientSpec(
            name: 'amir_1',
            totalBytes: 30 * 1024 ** 3,
            expiry: Expiry::afterFirstUse(self::MONTH),
            ipLimit: 2,
            telegramId: 42,
            comment: '42 | amir',
        ));

        self::assertSame(['POST /api/user'], $this->panel->calls());
        self::assertSame([
            'username' => 'amir_1',
            'group_ids' => [3, 5],
            'data_limit' => 30 * 1024 ** 3,
            'data_limit_reset_strategy' => 'no_reset',
            'status' => 'on_hold',
            'expire' => 0,
            'on_hold_expire_duration' => self::MONTH,
            'note' => '42 | amir',
        ], $this->panel->params(0), 'no IP limit (the panel has none), no credentials (the panel mints them)');

        self::assertSame('amir_1', $client->name);
        self::assertTrue($client->enabled);
        self::assertSame(30 * 1024 ** 3, $client->totalBytes);
        self::assertSame(self::MONTH, $client->expiry->pendingSeconds());
        self::assertSame('https://pg.example:8000/sub/dGVzdA.sig', $client->subscriptionUrl, 'a path is resolved against the panel');
        self::assertNull($client->online);
    }

    public function testADeadlineIsAnExpireAndNoTermIsExpireZero(): void
    {
        $deadline = new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC'));
        $provider = $this->provider([
            self::json(201, self::user(['status' => 'active', 'expire' => '2030-01-01T00:00:00Z', 'on_hold_expire_duration' => null])),
            self::json(201, self::user(['status' => 'active', 'on_hold_expire_duration' => null])),
        ]);

        $fixed = $provider->createClient(['3'], new ClientSpec('amir_1', expiry: Expiry::at($deadline)));
        $never = $provider->createClient(['3'], new ClientSpec('amir_2'));

        self::assertSame(['status' => 'active', 'expire' => $deadline->getTimestamp()], array_intersect_key($this->panel->params(0), ['status' => 1, 'expire' => 1]));
        self::assertArrayNotHasKey('on_hold_expire_duration', $this->panel->params(0));
        self::assertEquals($deadline, $fixed->expiry->deadline());
        self::assertSame(0, $this->panel->params(1)['expire']);
        self::assertArrayNotHasKey('note', $this->panel->params(1), 'nothing to say leaves the note alone');
        self::assertSame([null, null], [$never->expiry->deadline(), $never->expiry->pendingSeconds()]);
    }

    public function testCreateClientWithoutAGroupIsACallerMistake(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->provider([])->createClient([], new ClientSpec('amir_1'));
    }

    public function testAnUpdateReplacesTheLimitsAndTermInOnePutAndLeavesGroupsAlone(): void
    {
        $provider = $this->provider([
            self::json(200, self::user(['data_limit' => 60 * 1024 ** 3])),
            self::json(200, self::user(['data_limit' => 0, 'status' => 'active'])),
        ]);

        $client = $provider->updateClient(new ClientSpec('amir_1', 60 * 1024 ** 3, Expiry::afterFirstUse(2 * self::MONTH), comment: '42 | amir'));
        $provider->updateClient(new ClientSpec('amir_1'));

        self::assertSame(['PUT /api/user/by-username/amir_1', 'PUT /api/user/by-username/amir_1'], $this->panel->calls());
        self::assertSame(['data_limit' => 60 * 1024 ** 3, 'status' => 'on_hold', 'expire' => 0, 'on_hold_expire_duration' => 2 * self::MONTH, 'note' => '42 | amir'], $this->panel->params(0));
        self::assertSame(60 * 1024 ** 3, $client->totalBytes);
        // Unlimited and endless: 0 for each; no comment leaves the panel's note alone.
        self::assertSame(['data_limit' => 0, 'status' => 'active', 'expire' => 0], $this->panel->params(1));
    }

    public function testUpdatingAUserThePanelDoesNotHaveIsNotFound(): void
    {
        $provider = $this->provider([self::json(404, ['detail' => 'User not found'])]);

        $this->expectException(NotFoundException::class);

        $provider->updateClient(new ClientSpec('gone_1'));
    }

    public function testFindClientReadsTheCountersTheTermAndWhetherItWasSeenInTheLastTwoMinutes(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $provider = $this->provider([
            self::json(200, self::user(['status' => 'active', 'used_traffic' => 5 * 1024 ** 3, 'expire' => '2026-11-04T11:59:00', 'on_hold_expire_duration' => null, 'online_at' => '2026-10-05T11:58:30Z'])),
            self::json(200, self::user(['status' => 'disabled', 'data_limit' => null, 'online_at' => '2026-10-05T11:57:00+00:00'])),
            self::json(200, self::user(['status' => 'expired', 'expire' => '2026-10-01T00:00:00Z', 'on_hold_expire_duration' => null])),
        ]);

        $running = $provider->findClient('amir_1', presence: true);
        self::assertNotNull($running);
        self::assertSame('GET /api/user/by-username/amir_1', $this->panel->calls()[0]);
        self::assertSame([0, 5 * 1024 ** 3], [$running->uploadBytes, $running->downloadBytes], 'one total: PasarGuard has no upload/download split');
        self::assertSame('2026-11-04 11:59:00', $running->expiry->deadline()?->format('Y-m-d H:i:s'), 'a moment without a zone is UTC');
        self::assertTrue($running->online);
        self::assertSame('2026-10-05 11:58:30', $running->lastOnlineAt?->format('Y-m-d H:i:s'));

        $switchedOff = $provider->findClient('amir_1', presence: true);
        self::assertNotNull($switchedOff);
        self::assertFalse($switchedOff->enabled);
        self::assertSame(0, $switchedOff->totalBytes, 'no data limit = unlimited');
        self::assertSame(self::MONTH, $switchedOff->expiry->pendingSeconds(), 'switched off before its first connection, the term still waits for it');
        self::assertFalse($switchedOff->online, 'three minutes ago is not online');

        $ended = $provider->findClient('amir_1');
        self::assertNotNull($ended);
        self::assertTrue($ended->enabled, 'expired is ended by its term, not switched off');
        self::assertSame('2026-10-01', $ended->expiry->deadline()?->format('Y-m-d'));
        self::assertNull($ended->online, 'presence not asked: nobody can tell');
    }

    public function testFindClientIsNullForAMissingUserButAWrongAddressIsNoAnswer(): void
    {
        $provider = $this->provider([self::json(404, ['detail' => 'User not found']), self::json(404, ['detail' => 'Not Found'])]);

        self::assertNull($provider->findClient('gone_1'));

        $this->expectException(UnexpectedResponseException::class);
        $provider->findClient('amir_1');
    }

    public function testListClientsPagesThroughEveryUserByTheirUniqueNameWithTheirLinks(): void
    {
        $first = array_map(static fn(int $i): array => self::user(['username' => "user_{$i}"]), range(1, 500));
        $provider = $this->provider([
            self::json(200, ['users' => $first, 'total' => 501]),
            self::json(200, ['users' => [self::user(['username' => 'user_501', 'subscription_url' => 'https://sub.example.com/sub/abc'])], 'total' => 501]),
        ]);

        $clients = $provider->listClients();

        self::assertCount(501, $clients);
        self::assertSame('offset=0&limit=500&sort=username&load_sub=true', $this->panel->request(0)->getUri()->getQuery());
        self::assertSame('offset=500&limit=500&sort=username&load_sub=true', $this->panel->request(1)->getUri()->getQuery());
        self::assertSame('user_501', $clients[500]->name);
        self::assertSame('https://sub.example.com/sub/abc', $clients[500]->subscriptionUrl, 'a panel with a URL prefix answers a full link: kept as it is');
        self::assertNull($clients[0]->online, 'a list says nothing about presence');
    }

    public function testAFullPageThatReachesTheReportedTotalIsTheLast(): void
    {
        $provider = $this->provider([self::json(200, ['users' => array_map(static fn(int $i): array => self::user(['username' => "user_{$i}"]), range(1, 500)), 'total' => 500])]);

        self::assertCount(500, $provider->listClients());
        self::assertCount(1, $this->panel->calls(), 'no page is asked for beyond the total');
    }

    public function testAPanelThatNeverStopsAnsweringFullPagesIsNotListedAtOnce(): void
    {
        // The same full page over and over, with a total that never comes: the listing gives up after its last page.
        $page = self::json(200, ['users' => array_map(static fn(int $i): array => ['username' => "user_{$i}", 'status' => 'active'], range(1, 500)), 'total' => 1_000_000]);
        $provider = $this->provider(array_fill(0, 100, $page));

        try {
            $provider->listClients();
            self::fail('expected UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            self::assertStringContainsString('50000 users', $e->getMessage());
        }

        self::assertCount(100, $this->panel->calls(), 'the sync then asks for each service instead');
    }

    public function testSwitchingOffIsOnePutAndSwitchingOnKeepsATermThatNeverStarted(): void
    {
        $provider = $this->provider([
            self::json(200, self::user(['status' => 'disabled'])),
            self::json(200, self::user(['status' => 'disabled'])),
            self::json(200, self::user()),
            self::json(200, self::user(['status' => 'disabled', 'expire' => '2026-11-01T00:00:00Z', 'on_hold_expire_duration' => null])),
            self::json(200, self::user(['status' => 'active'])),
            self::json(200, self::user(['status' => 'active'])),
        ]);

        $provider->setClientEnabled('amir_1', false);
        self::assertSame(['status' => 'disabled'], $this->panel->params(0));

        // Never connected: back on hold with its term — a panel before 5.0 would make plain "active" endless.
        $provider->setClientEnabled('amir_1', true);
        self::assertSame(['GET /api/user/by-username/amir_1', 'PUT /api/user/by-username/amir_1'], array_slice($this->panel->calls(), 1, 2));
        self::assertSame(['status' => 'on_hold', 'on_hold_expire_duration' => self::MONTH], $this->panel->params(2));

        // Its clock had started.
        $provider->setClientEnabled('amir_1', true);
        self::assertSame(['status' => 'active'], $this->panel->params(4));

        // Not switched off at all: nothing to do.
        $provider->setClientEnabled('amir_1', true);
        self::assertSame('GET /api/user/by-username/amir_1', $this->panel->calls()[5]);
        self::assertCount(6, $this->panel->calls());
    }

    public function testDeleteAndResetAddressTheUserByName(): void
    {
        $provider = $this->provider([new Response(204), self::json(200, self::user()), self::json(404, ['detail' => 'User not found'])]);

        $provider->deleteClient('amir_1');
        $provider->resetClientTraffic('amir_1');

        self::assertSame(['DELETE /api/user/by-username/amir_1', 'POST /api/user/by-username/amir_1/reset'], $this->panel->calls());

        $this->expectException(NotFoundException::class);
        $provider->deleteClient('amir_1');
    }

    public function testRotatingRevokesTheSubscriptionAndAnswersTheNewLink(): void
    {
        $provider = $this->provider([self::json(200, self::user(['subscription_url' => '/sub/bmV3.sig']))]);

        $client = $provider->rotateClientCredentials('amir_1');

        self::assertSame(['POST /api/user/by-username/amir_1/revoke_sub'], $this->panel->calls());
        self::assertSame('https://pg.example:8000/sub/bmV3.sig', $client->subscriptionUrl);
    }

    public function testTheServersPrefixReplacesWhateverComesBeforeTheToken(): void
    {
        $provider = $this->provider([self::json(201, self::user(['subscription_url' => 'http://10.0.0.5:8000/sub/dGVzdA.sig']))], ['subscription_url' => 'https://sub.example.com/s']);

        self::assertSame('https://sub.example.com/s/dGVzdA.sig', $provider->createClient(['3'], new ClientSpec('amir_1'))->subscriptionUrl);
    }

    public function testThePanelAlwaysServesSubscriptionsWithoutBeingAsked(): void
    {
        self::assertTrue($this->provider([])->servesSubscriptions());
        self::assertSame([], $this->panel->calls());
    }

    public function testGroupsAreTheInboundsAPlanSells(): void
    {
        $tags = array_map(static fn(int $i): string => "VLESS Reality {$i}", range(1, 12));
        $provider = $this->provider([self::json(200, ['groups' => [
            ['id' => 3, 'name' => 'VIP', 'inbound_tags' => ['VLESS TCP', 'Trojan WS'], 'is_disabled' => false, 'total_users' => 12],
            ['id' => 5, 'name' => 'Germany', 'inbound_tags' => $tags, 'is_disabled' => true, 'total_users' => 0],
        ], 'total' => 2])]);

        [$vip, $germany] = $provider->listInbounds();

        self::assertSame(['GET /api/groups'], $this->panel->calls());
        self::assertSame(['3', 'VIP', 'VLESS TCP, Trojan WS', true, 12], [$vip->key, $vip->remark, $vip->tag, $vip->enabled, $vip->clientCount]);
        self::assertSame([null, null, null, null], [$vip->protocol, $vip->port, $vip->network, $vip->security], 'a group is no single protocol or port: nothing made up');
        self::assertFalse($germany->enabled);
        self::assertSame(128, mb_strlen($germany->tag), 'the tags are cut to what the shop keeps');
        self::assertStringEndsWith('…', $germany->tag);
    }

    public function testStatusIsTheHostsVitalsWithTheCoreUnknown(): void
    {
        $provider = $this->provider([self::json(200, [
            'version' => '5.4.1', 'uptime_seconds' => 3600, 'mem_total' => 4096, 'mem_used' => 1024, 'disk_total' => 8192, 'disk_used' => 2048,
            'cpu_cores' => 2, 'cpu_usage' => 12.5, 'total_user' => 3, 'online_users' => 1, 'active_users' => 2, 'on_hold_users' => 1,
            'disabled_users' => 0, 'expired_users' => 0, 'limited_users' => 0, 'incoming_bandwidth' => 0, 'outgoing_bandwidth' => 0,
        ])]);

        $status = $provider->status();

        self::assertSame(['GET /api/system'], $this->panel->calls());
        self::assertSame([12.5, 1024, 4096, 2048, 8192, 3600], [$status->cpuPercent, $status->memoryUsedBytes, $status->memoryTotalBytes, $status->diskUsedBytes, $status->diskTotalBytes, $status->uptimeSeconds]);
        self::assertSame(CoreState::Unknown, $status->coreState, 'the cores run on the nodes');
        self::assertNull($status->coreName);
    }

    public function testTheConnectionTestReadsOneUser(): void
    {
        $provider = $this->provider([self::json(200, ['users' => [], 'total' => 0])]);

        $provider->testConnection();

        self::assertSame('/api/users', $this->panel->request(0)->getUri()->getPath());
        self::assertSame('limit=1', $this->panel->request(0)->getUri()->getQuery());
    }

    /**
     * @param list<Response> $responses
     * @param array<string, mixed> $meta
     */
    private function provider(array $responses, array $meta = []): ProviderInterface
    {
        $this->app(); // registers the encrypter the Server casts need

        $this->panel = new FakePanel();
        $this->panel->raw(...$responses);

        $server = new Server([
            'name' => 'PG',
            'driver' => 'pasarguard',
            'base_url' => 'https://pg.example:8000',
            'api_token' => self::KEY,
            'meta' => $meta,
        ]);

        return (new PasarGuardDriver())->connect($server, new PanelHttp($this->panel->client(), new NullLogger()));
    }

    /**
     * A user as PasarGuard answers one: on hold for a month, never connected, its link a path.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function user(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'username' => 'amir_1',
            'status' => 'on_hold',
            'used_traffic' => 0,
            'lifetime_used_traffic' => 0,
            'data_limit' => 30 * 1024 ** 3,
            'data_limit_reset_strategy' => 'no_reset',
            'expire' => null,
            'on_hold_expire_duration' => self::MONTH,
            'on_hold_timeout' => null,
            'note' => '42 | amir',
            'group_ids' => [3],
            'online_at' => null,
            'created_at' => '2026-10-05T10:00:00Z',
            'edit_at' => null,
            'subscription_url' => '/sub/dGVzdA.sig',
            'proxy_settings' => ['vless' => ['id' => 'b1c0e3f4-0000-4000-8000-000000000000']],
            'hwid_limit' => null,
            'next_plan' => null,
        ];
    }

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}
