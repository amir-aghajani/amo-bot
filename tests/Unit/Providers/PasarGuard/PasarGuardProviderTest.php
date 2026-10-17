<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\PasarGuard;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\Drivers\PasarGuard\PasarGuardDriver;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\CoreState;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * The generic ProviderInterface on PasarGuard: users as clients, groups as inbounds, "on hold" for a term waiting for the
 * first connection, and the signed link made absolute.
 */
final class PasarGuardProviderTest extends TestCase
{
    private const KEY = 'pg_key_6f1c2a3b-4d5e-4f60-8a1b-2c3d4e5f6a7b';
    private const MONTH = 30 * 86400;

    /** A user's proxy settings with every protocol on: each one's secret, a vless flow and a Shadowsocks method beside them. */
    private const SETTINGS = [
        'vless' => ['id' => 'b1c0e3f4-1234-4abc-8def-0123456789ab', 'flow' => 'xtls-rprx-vision'],
        'vmess' => ['id' => 'c2d1f4a5-1234-4abc-8def-0123456789ab'],
        'trojan' => ['password' => 'Tr0jan-Pass-1'],
        'shadowsocks' => ['password' => 'Sh4dow-Pass', 'method' => 'chacha20-ietf-poly1305'],
        'hysteria2' => ['auth' => 'Hy2-Auth-Secret-1'],
    ];

    /** A subscription token as PasarGuard signs one (a payload and its signature), and one it no longer takes. */
    private const TOKEN = 'YW1pcl8xLDE3NjAyNjI0MDA.c2lnbmF0dXJlLTE';
    private const REVOKED = 'cmV2b2tlZF8xLDE3NjAyNjI0MDA.c2lnbmF0dXJlLTI';

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

    public function testAClientWantedSwitchedOffIsSwitchedOffAsItIsMade(): void
    {
        $provider = $this->provider([self::json(201, self::user()), self::json(200, self::user(['status' => 'disabled']))]);

        $client = $provider->createClient(['3'], new ClientSpec('amir_1', 10, Expiry::afterFirstUse(self::MONTH), enabled: false));

        self::assertSame(['POST /api/user', 'PUT /api/user/by-username/amir_1'], $this->panel->calls(), 'PasarGuard makes a user active or on hold only');
        self::assertSame('on_hold', $this->panel->params(0)['status']);
        self::assertSame(['status' => 'disabled'], $this->panel->params(1), 'its waiting term left on it');
        self::assertFalse($client->enabled);
        self::assertSame(self::MONTH, $client->expiry->pendingSeconds());
    }

    public function testAUserThePanelWillNotSwitchOffIsTakenBackOrElseFailsAsMade(): void
    {
        $refusal = static fn(): Response => self::json(500, ['detail' => 'database is locked']);
        $provider = $this->provider([self::json(201, self::user()), $refusal(), new Response(204)]);
        try {
            $provider->createClient(['3'], new ClientSpec('amir_1', enabled: false));
            self::fail('the switch was refused');
        } catch (ProviderException $e) {
            self::assertSame(['POST /api/user', 'PUT /api/user/by-username/amir_1', 'DELETE /api/user/by-username/amir_1'], $this->panel->calls());
            self::assertFalse($e->mayHaveBeenDone(), 'taken back: nothing was made');
        }

        $provider = $this->provider([self::json(201, self::user()), $refusal(), $refusal()]);
        try {
            $provider->createClient(['3'], new ClientSpec('amir_1', enabled: false));
            self::fail('the switch was refused');
        } catch (ProviderException $e) {
            self::assertTrue($e->mayHaveBeenDone(), 'still there, switched on: whoever asked looks at it');
        }
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

    public function testAnUpdateOfAUserKeptOffSendsItDisabledWithItsTerm(): void
    {
        $provider = $this->provider([
            self::json(200, self::user(['status' => 'disabled'])),
            self::json(200, self::user(['status' => 'disabled', 'expire' => '2026-11-01T00:00:00Z', 'on_hold_expire_duration' => null])),
        ]);

        $client = $provider->updateClient(new ClientSpec('amir_1', 10, Expiry::afterFirstUse(self::MONTH), enabled: false));
        $provider->updateClient(new ClientSpec('amir_1', 10, Expiry::at(new \DateTimeImmutable('2026-11-01 00:00:00', new \DateTimeZone('UTC'))), enabled: false));

        // A term still waiting keeps its duration, for the switch to put it back on hold.
        self::assertSame(['data_limit' => 10, 'status' => 'disabled', 'expire' => 0, 'on_hold_expire_duration' => self::MONTH], $this->panel->params(0));
        self::assertFalse($client->enabled);
        self::assertSame(['data_limit' => 10, 'status' => 'disabled', 'expire' => 1793491200], $this->panel->params(1));
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

    /** A deadline written as unix seconds (as the shop sends it) reads as one, and `expire: 0` as none — never "no end". */
    public function testADeadlineAsUnixSecondsAndNoneAsZeroReadAsTheyAreMeant(): void
    {
        $provider = $this->provider([
            self::json(200, self::user(['status' => 'active', 'expire' => 1793491200, 'on_hold_expire_duration' => null])),
            self::json(200, self::user(['status' => 'on_hold', 'expire' => 0])),
            self::json(200, self::user(['status' => 'active', 'expire' => 0, 'on_hold_expire_duration' => null])),
        ]);

        [$fixed, $onHold, $never] = array_map(static fn(): ?ClientInfo => $provider->findClient('amir_1'), range(1, 3));
        self::assertNotNull($fixed);
        self::assertNotNull($onHold);
        self::assertNotNull($never);

        self::assertSame(1793491200, $fixed->expiry->deadline()?->getTimestamp());
        self::assertSame(self::MONTH, $onHold->expiry->pendingSeconds(), 'on hold with its duration: the term waits for the first connection');
        self::assertSame([null, null], [$never->expiry->deadline(), $never->expiry->pendingSeconds()], 'no end at all');
    }

    public function testAPageWithoutATotalPagesOnUntilAShortOne(): void
    {
        $full = self::json(200, ['users' => array_map(static fn(int $i): array => self::user(['username' => "user_{$i}"]), range(1, 500))]);
        $provider = $this->provider([$full, self::json(200, ['users' => [self::user(['username' => 'user_501'])]])]);

        self::assertCount(501, $provider->listClients(), 'no total is no "zero users": the next page is asked for');
        self::assertCount(2, $this->panel->calls());
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

    public function testAShortPageIsTheLastEvenBelowTheReportedTotal(): void
    {
        // A total that counts users the page does not show (deleted meanwhile): the short page ends the listing.
        $provider = $this->provider([self::json(200, ['users' => [self::user(), self::user(['username' => 'amir_2'])], 'total' => 900])]);

        self::assertSame(['amir_1', 'amir_2'], array_map(static fn(ClientInfo $client): string => $client->name, $provider->listClients()));
        self::assertCount(1, $this->panel->calls());
    }

    public function testAConfigIsTheUserWhoseProxySettingsHoldItsCredentialPagedWithoutLinksThenReadByName(): void
    {
        $first = array_map(static fn(int $i): array => self::user(['username' => "user_{$i}", 'subscription_url' => null, 'proxy_settings' => ['vless' => ['id' => sprintf('00000000-0000-4000-8000-%012d', $i)]]]), range(1, 500));
        $provider = $this->provider([
            self::json(200, ['users' => $first, 'total' => 502]),
            self::json(200, ['users' => [self::user(['username' => 'amir_501', 'subscription_url' => null, 'proxy_settings' => self::SETTINGS]), self::user(['username' => 'amir_502', 'subscription_url' => null])], 'total' => 502]),
            self::json(200, self::user(['username' => 'amir_501'])), // the user found, read with its link
        ]);

        $client = $provider->findClientByLink(self::link('trojan://Tr0jan-Pass-1@pg.example:443?sni=pg.example#amir'));

        self::assertSame(['GET /api/users', 'GET /api/users', 'GET /api/user/by-username/amir_501'], $this->panel->calls(), 'the paging stops at the user found, then reads it');
        self::assertSame('offset=0&limit=500&sort=username&load_sub=false', $this->panel->request(0)->getUri()->getQuery(), 'no link signed for every user');
        self::assertSame('offset=500&limit=500&sort=username&load_sub=false', $this->panel->request(1)->getUri()->getQuery());
        self::assertSame('amir_501', $client?->name);
        self::assertSame('https://pg.example:8000/sub/dGVzdA.sig', $client->subscriptionUrl, 'its link from its own read');
        self::assertNull($client->ipLimit, 'PasarGuard keeps no IP limit');
    }

    public function testEveryProtocolsSecretIsACredentialButNothingElseOfItsSettings(): void
    {
        $sip002 = 'ss://' . rtrim(strtr(base64_encode('chacha20-ietf-poly1305:Sh4dow-Pass'), '+/', '-_'), '=') . '@pg.example:8388#amir';
        foreach ([
            'vless, its id' => ['vless://B1C0E3F4-1234-4ABC-8DEF-0123456789AB@pg.example:443?flow=xtls-rprx-vision', 'amir_1'],
            'vmess, its id' => ['vmess://c2d1f4a5-1234-4abc-8def-0123456789ab@pg.example:443', 'amir_1'],
            'trojan, its password' => ['trojan://Tr0jan-Pass-1@pg.example:443', 'amir_1'],
            'Shadowsocks, its password' => [$sip002, 'amir_1'],
            'hysteria2, its auth' => ['hysteria2://Hy2-Auth-Secret-1@pg.example:443', 'amir_1'],
            'a flow' => ['trojan://xtls-rprx-vision@pg.example:443', null],
            'a method' => ['trojan://chacha20-ietf-poly1305@pg.example:443', null],
        ] as $case => [$config, $name]) {
            $provider = $this->provider([self::json(200, ['users' => [self::user(['proxy_settings' => self::SETTINGS])], 'total' => 1]), self::json(200, self::user())]);

            self::assertSame($name, $provider->findClientByLink(self::link($config))?->name, $case);
            self::assertSame($name === null ? ['GET /api/users'] : ['GET /api/users', 'GET /api/user/by-username/amir_1'], $this->panel->calls(), $case);
        }
    }

    public function testAConfigNoUserHasIsNoneOnceTheReportedTotalIsRead(): void
    {
        $provider = $this->provider([self::json(200, ['users' => array_map(static fn(int $i): array => self::user(['username' => "user_{$i}"]), range(1, 500)), 'total' => 500])]);

        self::assertNull($provider->findClientByLink(self::link('trojan://Tr0jan-Pass-1@pg.example:443')));
        self::assertCount(1, $this->panel->calls(), 'no page beyond the total');
    }

    public function testASubscriptionLinksTokenIsShownToThePanelsOwnRouteWithoutTheApisCredentials(): void
    {
        $provider = $this->provider([
            // One user, read for the path its links live under.
            self::json(200, ['users' => [self::user(['username' => 'zed_9', 'subscription_url' => 'https://sub.example.com/sub/WnpaenphYmMsMTc2MDI2MjQwMA.c2ln'])], 'total' => 40]),
            self::json(200, ['username' => 'amir_1', 'status' => 'active', 'used_traffic' => 0]), // /sub/{token}/info
            self::json(200, self::user()),                                                        // the user it names
        ]);

        $client = $provider->findClientByLink(self::link('سرویسم: https://sub.example.com/sub/' . self::TOKEN . '/v2ray-json'));

        self::assertSame([
            'GET /api/users',
            'GET /sub/' . self::TOKEN . '/info',
            'GET /api/user/by-username/amir_1',
        ], $this->panel->calls(), 'the token alone: the format after it is too short to be one');
        self::assertSame('offset=0&limit=1&load_sub=true', $this->panel->request(0)->getUri()->getQuery());
        self::assertSame('pg.example', $this->panel->request(1)->getUri()->getHost(), 'the panel\'s own address, whatever host its links name');
        self::assertSame(self::KEY, $this->panel->request(0)->getHeaderLine('X-Api-Key'));
        self::assertFalse($this->panel->request(1)->hasHeader('X-Api-Key'), 'a client app\'s request carries no key');
        self::assertFalse($this->panel->request(1)->hasHeader('Authorization'), 'nor a token');
        self::assertSame('amir_1', $client?->name);
        self::assertSame('https://pg.example:8000/sub/dGVzdA.sig', $client->subscriptionUrl);
    }

    public function testAPrefixTheOwnerPutBeforeTheLinksIsNotThePanelsPath(): void
    {
        $provider = $this->provider([
            // Links under a proxy's path (the owner's prefix): the panel itself serves them at /sub.
            self::json(200, ['users' => [self::user(['subscription_url' => 'https://cdn.example.com/vpn/links/sub/dGVzdA.sig'])], 'total' => 1]),
            self::json(200, ['username' => 'amir_1']),
            self::json(200, self::user()),
        ]);

        self::assertSame('amir_1', $provider->findClientByLink(self::link('https://cdn.example.com/vpn/links/sub/' . self::TOKEN))?->name);
        self::assertSame('GET /sub/' . self::TOKEN . '/info', $this->panel->calls()[1], 'only the folder the token is in');
    }

    public function testAMessageWithALinkAndAConfigShowsTheLinksTokenFirst(): void
    {
        $provider = $this->provider([
            self::json(200, ['users' => [self::user()], 'total' => 1]), // the path
            self::json(200, ['username' => 'amir_1']),
            self::json(200, self::user()),
        ]);

        self::assertSame('amir_1', $provider->findClientByLink(self::link("vless://00000000-0000-4000-8000-000000000000@1.2.3.4:443\nhttps://pg.example:8000/sub/" . self::TOKEN))?->name);
        self::assertSame(['GET /api/users', 'GET /sub/' . self::TOKEN . '/info', 'GET /api/user/by-username/amir_1'], $this->panel->calls(), 'found by its token: no user listed for the config');
    }

    public function testTheConfigIsLookedForOnceTheLinksTokenIsNobodys(): void
    {
        $provider = $this->provider([
            self::json(200, ['users' => [self::user()], 'total' => 1]),                                     // the path
            self::json(404, ['detail' => 'Not Found']),                                                     // an old link's token
            self::json(200, ['users' => [self::user(['proxy_settings' => self::SETTINGS])], 'total' => 1]), // the users, for the config
            self::json(200, self::user()),
        ]);

        self::assertSame('amir_1', $provider->findClientByLink(self::link('https://pg.example:8000/sub/' . self::REVOKED . "\nvless://B1C0E3F4-1234-4ABC-8DEF-0123456789AB@1.2.3.4:443"))?->name);
        self::assertSame(['GET /api/users', 'GET /sub/' . self::REVOKED . '/info', 'GET /api/users', 'GET /api/user/by-username/amir_1'], $this->panel->calls());
        self::assertSame('offset=0&limit=500&sort=username&load_sub=false', $this->panel->request(2)->getUri()->getQuery());
    }

    public function testATokenThePanelRefusesIsNotThatUsersAndTheNextIsShown(): void
    {
        $provider = $this->provider([
            self::json(200, ['users' => [self::user()], 'total' => 1]),
            self::json(403, ['detail' => 'Invalid subscription token']),
        ]);

        self::assertNull($provider->findClientByLink(self::link('https://pg.example:8000/sub/' . self::REVOKED)));
        self::assertSame(['GET /api/users', 'GET /sub/' . self::REVOKED . '/info'], $this->panel->calls(), 'no config: no user listed');

        $provider = $this->provider([
            self::json(200, ['users' => [self::user()], 'total' => 1]),
            self::json(403, ['detail' => 'Invalid subscription token']),
            self::json(200, ['username' => 'amir_1']),
            self::json(200, self::user()),
        ]);

        self::assertSame('amir_1', $provider->findClientByLink(self::link('قدیمی: https://pg.example:8000/sub/' . self::REVOKED . ' جدید: https://pg.example:8000/sub/' . self::TOKEN))?->name);
        self::assertSame(['GET /api/users', 'GET /sub/' . self::REVOKED . '/info', 'GET /sub/' . self::TOKEN . '/info', 'GET /api/user/by-username/amir_1'], $this->panel->calls());
    }

    public function testThreeTokensAtMostAreShownToThePanelAcrossTheLinks(): void
    {
        [$a, $b, $c, $d] = array_map(static fn(string $letter): string => str_repeat($letter, 20), ['a', 'b', 'c', 'd']);
        $provider = $this->provider([
            self::json(200, ['users' => [self::user()], 'total' => 1]),
            self::json(404, ['detail' => 'Not Found']),
            self::json(404, ['detail' => 'Not Found']),
            self::json(404, ['detail' => 'Not Found']),
        ]);

        self::assertNull($provider->findClientByLink(self::link("https://pg.example:8000/{$a}/{$b}\nhttps://pg.example:8000/{$c}/{$d}")));
        self::assertSame(['GET /api/users', "GET /sub/{$b}/info", "GET /sub/{$a}/info", "GET /sub/{$d}/info"], $this->panel->calls(), 'each a request: the fourth is never asked');
    }

    public function testAPartThatCannotBeATokenIsNeverShownToThePanel(): void
    {
        foreach ([
            'shorter than sixteen' => 'https://pg.example:8000/sub/cmV2b2tlZA.c2ln',
            'four dotted parts' => 'https://pg.example:8000/sub/aaaaaa.bbbbbb.cccccc.dddddd',
            'padding' => 'https://pg.example:8000/sub/YWJjZGVmZ2hpamtsbW5vcA%3D%3D',
            'a slash inside' => 'https://pg.example:8000/sub/abcdefgh%2Fijklmnopqrst',
        ] as $case => $link) {
            $provider = $this->provider([]);

            self::assertNull($provider->findClientByLink(self::link($link)), $case);
            self::assertSame([], $this->panel->calls(), "{$case}: not even the path is read");
        }
    }

    public function testWithoutAUserToReadThereIsNoSubscriptionPathToAsk(): void
    {
        foreach ([
            'no user' => ['users' => [], 'total' => 0],
            'a link at the root' => ['users' => [self::user(['subscription_url' => '/dGVzdA.sig'])], 'total' => 1],
        ] as $case => $answer) {
            $provider = $this->provider([self::json(200, $answer)]);

            self::assertNull($provider->findClientByLink(self::link('https://pg.example:8000/sub/' . self::TOKEN)), $case);
            self::assertSame(['GET /api/users'], $this->panel->calls(), $case);
        }
    }

    public function testAnythingButAUserFromTheRouteIsNoAnswerAndNoFailure(): void
    {
        foreach ([
            'a server error' => self::json(500, ['detail' => 'Internal Server Error']),
            'a redirect' => new Response(302, ['Location' => 'https://pg.example:8000/dashboard/login']),
            'a web page' => new Response(200, ['Content-Type' => 'text/html'], '<html><body>Not here</body></html>'),
        ] as $case => $answer) {
            $provider = $this->provider([self::json(200, ['users' => [self::user()], 'total' => 1]), $answer]);

            self::assertNull($provider->findClientByLink(self::link('https://pg.example:8000/sub/' . self::TOKEN)), $case);
            self::assertCount(2, $this->panel->calls(), $case);
        }
    }

    public function testNothingAnsweringTheRouteFailsWithoutTheTokenInWhatItSays(): void
    {
        $address = 'https://pg.example:8000/sub/' . self::TOKEN . '/info';
        $provider = $this->provider([self::json(200, ['users' => [self::user()], 'total' => 1])]);
        $this->panel->refuse(new ConnectException("cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for {$address}", new Request('GET', $address)));

        try {
            $provider->findClientByLink(self::link('https://pg.example:8000/sub/' . self::TOKEN));
            self::fail('nothing answered');
        } catch (ProviderException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringContainsString('timed out', $e->getMessage());
        }
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
            ['id' => 6, 'name' => 'Old', 'inbound_tags' => [], 'is_disabled' => 'true'],
        ], 'total' => 3])]);

        [$vip, $germany, $old] = $provider->listInbounds();

        self::assertSame(['GET /api/groups'], $this->panel->calls());
        self::assertSame(['3', 'VIP', 'VLESS TCP, Trojan WS', true, 12], [$vip->key, $vip->remark, $vip->tag, $vip->enabled, $vip->clientCount]);
        self::assertSame([null, null, null, null], [$vip->protocol, $vip->port, $vip->network, $vip->security], 'a group is no single protocol or port: nothing made up');
        self::assertFalse($germany->enabled);
        self::assertSame(implode(', ', $tags), $germany->tag, 'every tag, as the panel has them: the shop\'s copy cuts what it keeps');
        self::assertFalse($old->enabled, 'a switch as loosely typed as any');
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

    private static function link(string $text): ClientLink
    {
        return ClientLink::find($text) ?? self::fail("No link in {$text}.");
    }
}
