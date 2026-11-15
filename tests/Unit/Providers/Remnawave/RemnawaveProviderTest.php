<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Remnawave;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\Drivers\Remnawave\RemnawaveApiException;
use App\Modules\Providers\Drivers\Remnawave\RemnawaveDriver;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\DTO\InboundInfo;
use App\Modules\Providers\Enums\CoreState;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
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
 * The generic ProviderInterface on Remnawave, against answers shaped as its NestJS API writes them (libs/contract
 * commands/users, internal-squads, system; src/modules/users/users.service.ts): its internal squads as the inbounds,
 * several a user; a user read by its username, then addressed by its numeric id (3.x) or its uuid (2.x); its deadline —
 * a first-connection term started at the sale, "never" in 2099, none sent that it would refuse as past —; its status as
 * its update and actions switch it (a PATCH turns off only an active user; time or traffic given back turns one on);
 * its users listed 1,000 a page; and a link's short id or a config's credentials looked up.
 */
final class RemnawaveProviderTest extends TestCase
{
    private const TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1dWlkIjoiNWIwYzdlMmEtOTFkNC00YzNmLThlNmEtMmY0YjlkMWM3YTMwIiwidXNlcm5hbWUiOm51bGwsInJvbGUiOiJBUEkiLCJpYXQiOjE3NjAwMDAwMDAsImV4cCI6MTc5MTUzNjAwMH0.a0AfkgXIXEKU9nW9aNb6MgPc0Eo7xjqCdzBkUlGGLhI';
    private const BASE = 'https://rw.example';
    private const NOW = '2026-11-15 10:00:00';
    private const MONTH = 30 * 86400;
    private const GIB = 1024 ** 3;

    /** Two internal squads (their uuids as Remnawave mints them, version 4). */
    private const DE = 'a1b2c3d4-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
    private const NL = 'b2c3d4e5-6f7a-4b8c-9dae-1f2a3b4c5d6e';

    /** The user's subscription short id and its proxies' credentials. */
    private const SHORT = 'Xk3pQ9vLm2Rt7Wz5';
    private const VLESS = '3c5e7f9a-1b2d-4e6f-8a0b-2c4d6e8f0a1b';
    private const TROJAN = 'Tr0janPassw0rdAbCdEf12';
    private const SS = 'Sh4d0wP4ssw0rdXyZ0123';

    /** A 2.x user's uuid, what its paths and its PATCH take there. */
    private const UUID = '9f8e7d6c-5b4a-4392-8170-6f5e4d3c2b1a';

    private FakePanel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(new Carbon(self::NOW, 'UTC'));
    }

    public function testTheConnectionTestReadsOneUser(): void
    {
        $provider = $this->provider([self::answer(['users' => [self::user()], 'total' => 1])]);

        $provider->testConnection();

        self::assertSame(['GET /api/users'], $this->panel->calls());
        self::assertSame('start=0&size=1', $this->panel->request(0)->getUri()->getQuery());

        $provider = $this->provider([self::json(401, ['message' => 'Unauthorized', 'statusCode' => 401])]);
        $this->expectException(AuthenticationException::class);
        $provider->testConnection();
    }

    /** GET /api/system/stats: the panel host's memory and uptime; Remnawave reports no CPU load nor disk, its cores run on its nodes. */
    public function testTheStatusIsThePanelHostsMemoryAndUptime(): void
    {
        $provider = $this->provider([self::answer(self::stats())]);

        $status = $provider->status();

        self::assertSame(['GET /api/system/stats'], $this->panel->calls());
        self::assertNull($status->cpuPercent, 'no CPU load reported: none shown, rather than 0%');
        self::assertSame([3 * self::GIB, 8 * self::GIB, 0, 0, 86400], [$status->memoryUsedBytes, $status->memoryTotalBytes, $status->diskUsedBytes, $status->diskTotalBytes, $status->uptimeSeconds]);
        self::assertSame([CoreState::Unknown, null, ''], [$status->coreState, $status->coreName, $status->coreVersion]);
    }

    public function testTheInboundsAreItsInternalSquadsKeyedByUuidWithTheirMembers(): void
    {
        $provider = $this->provider([self::answer(['total' => 3, 'internalSquads' => [
            self::squad(self::DE, 'Germany', ['VLESS_REALITY_DE', 'TROJAN_WS_DE'], 120),
            self::squad('c3d4e5f6-7a8b-4c9d-aeb0-2a3b4c5d6e7f', 'Empty', [], 0),
            self::squad(self::NL, 'Netherlands', ['VLESS_REALITY_NL'], 7),
        ]])]);

        $inbounds = $provider->listInbounds();

        self::assertSame(['GET /api/internal-squads'], $this->panel->calls());
        self::assertSame([self::DE, 'c3d4e5f6-7a8b-4c9d-aeb0-2a3b4c5d6e7f', self::NL], array_map(static fn(InboundInfo $inbound): string => $inbound->key, $inbounds));
        [$de, $empty, $nl] = $inbounds;
        self::assertSame(['Germany', 'VLESS_REALITY_DE, TROJAN_WS_DE', true, 120], [$de->remark, $de->tag, $de->enabled, $de->clientCount]);
        self::assertSame(['Empty', '', false, 0], [$empty->remark, $empty->tag, $empty->enabled, $empty->clientCount], 'a squad without an inbound gives a user nothing');
        self::assertSame(['Netherlands', 7], [$nl->remark, $nl->clientCount]);
        self::assertSame([null, null, null, null], [$de->protocol, $de->port, $de->network, $de->security], 'a squad is no single inbound: nothing made up');
    }

    public function testThePanelAlwaysServesSubscriptionsWithoutBeingAsked(): void
    {
        self::assertTrue($this->provider([])->servesSubscriptions());
        self::assertSame([], $this->panel->calls());
    }

    /** One POST (create-user.command.ts): every squad asked for, its deadline (required), no reset on a schedule, the comment. */
    public function testANewClientIsMadeInEverySquadAskedForInOneCall(): void
    {
        $deadline = new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC'));
        $provider = $this->provider([self::answer(self::user(['expireAt' => '2030-01-01T00:00:00.000Z', 'activeInternalSquads' => [['uuid' => self::DE, 'name' => 'Germany'], ['uuid' => self::NL, 'name' => 'Netherlands']]]), 201)]);

        $client = $provider->createClient([self::DE, self::NL, self::DE], new ClientSpec('amir_1', 30 * self::GIB, Expiry::at($deadline), ipLimit: 2, telegramId: 42, comment: ' 42 | amir '));

        self::assertSame(['POST /api/users'], $this->panel->calls(), 'Remnawave answers the user it made');
        self::assertBody([
            'username' => 'amir_1',
            'status' => 'ACTIVE',
            'trafficLimitBytes' => 30 * self::GIB,
            'trafficLimitStrategy' => 'NO_RESET',
            'expireAt' => '2030-01-01T00:00:00.000Z',
            'activeInternalSquads' => [self::DE, self::NL],
            'hwidDeviceLimit' => 2,
            'description' => '42 | amir',
            'telegramId' => 42,
        ], $this->panel->params(0), 'the plan\'s devices as its HWID limit, no credentials (the panel mints them)');

        self::assertSame(['amir_1', true, 30 * self::GIB, 0, 0, null, null], [$client->name, $client->enabled, $client->totalBytes, $client->uploadBytes, $client->downloadBytes, $client->ipLimit, $client->online], 'the panel answered no HWID limit of its own: unsaid');
        self::assertEquals($deadline, $client->expiry->deadline());
        self::assertSame('https://sub.example.com/' . self::SHORT, $client->subscriptionUrl);
    }

    /** Remnawave takes a user switched off as it is made (status DISABLED): no switch-off after it. */
    public function testAClientWantedSwitchedOffIsMadeOffInTheSameCall(): void
    {
        $provider = $this->provider([self::answer(self::user(['status' => 'DISABLED']), 201)]);

        $client = $provider->createClient([self::DE], new ClientSpec('amir_1', 10 * self::GIB, Expiry::afterFirstUse(self::MONTH), enabled: false));

        self::assertSame(['POST /api/users'], $this->panel->calls());
        self::assertSame('DISABLED', $this->panel->params(0)['status']);
        self::assertFalse($client->enabled);
    }

    /** Remnawave keeps no term waiting for the first connection: it starts at the sale, and reads back as the deadline it got. */
    public function testATermWaitingForTheFirstConnectionStartsAtTheSale(): void
    {
        $provider = $this->provider([self::answer(self::user(['expireAt' => '2026-12-15T10:00:00.000Z']), 201)]);

        $client = $provider->createClient([self::DE], new ClientSpec('amir_1', 30 * self::GIB, Expiry::afterFirstUse(self::MONTH)));

        self::assertSame('2026-12-15T10:00:00.000Z', $this->panel->params(0)['expireAt'], 'now plus the term, to the millisecond, in UTC');
        self::assertSame([null, '2026-12-15 10:00:00'], [$client->expiry->pendingSeconds(), $client->expiry->deadline()?->format('Y-m-d H:i:s')], 'the shop mirrors the deadline the panel has');
    }

    /** Remnawave's own "unlimited" is a deadline in 2099 (src/modules/subscription/utils/get-user-info.headers.ts): sent so, read so. */
    public function testNoTermIsADeadlineIn2099BothWays(): void
    {
        $provider = $this->provider([
            self::answer(self::user(['expireAt' => '2099-12-31T00:00:00.000Z', 'trafficLimitBytes' => 0]), 201),
            self::answer(self::user(['expireAt' => '2099-01-01T00:00:00.000Z'])),
            self::answer(self::user(['expireAt' => '2100-06-01T00:00:00.000Z'])),
            self::answer(self::user(['expireAt' => '2098-12-31T23:59:59.000Z'])),
        ]);

        $created = $provider->createClient([self::DE], new ClientSpec('amir_1'));

        self::assertSame('2099-12-31T00:00:00.000Z', $this->panel->params(0)['expireAt']);
        self::assertSame(0, $this->panel->params(0)['trafficLimitBytes'], '0 is no limit');
        self::assertSame([null, null, 0], [$created->expiry->deadline(), $created->expiry->pendingSeconds(), $created->totalBytes]);
        self::assertNull($provider->findClient('amir_1')?->expiry->deadline(), 'any moment of 2099 is never');
        self::assertNull($provider->findClient('amir_1')?->expiry->deadline(), 'and after');
        self::assertSame('2098-12-31 23:59:59', $provider->findClient('amir_1')?->expiry->deadline()?->format('Y-m-d H:i:s'), 'the year before is a deadline');
    }

    /**
     * No comment leaves the panel's description alone; no Telegram id is sent as none — Remnawave keeps a field left out,
     * and a service handed to another customer keeps no trace of the one before.
     */
    public function testNoCommentLeavesTheDescriptionAloneNoTelegramIdClearsItAndAUserNeedsASquad(): void
    {
        $provider = $this->provider([self::answer(self::user(), 201), self::answer(self::user()), self::answer(self::user(['telegramId' => null]))]);

        $provider->createClient([self::DE], new ClientSpec('amir_1', -5));

        self::assertArrayNotHasKey('description', $this->panel->params(0));
        self::assertArrayHasKey('telegramId', $this->panel->params(0));
        self::assertNull($this->panel->params(0)['telegramId']);
        self::assertSame(0, $this->panel->params(0)['trafficLimitBytes'], 'never a negative limit');

        $provider->updateClient(new ClientSpec('amir_1', 30 * self::GIB, Expiry::at(new \DateTimeImmutable('2026-12-15 10:00:00 UTC'))));
        self::assertArrayNotHasKey('description', $this->panel->params(2), 'the description the panel has, kept');
        self::assertArrayHasKey('telegramId', $this->panel->params(2));
        self::assertNull($this->panel->params(2)['telegramId'], 'the previous owner\'s Telegram id taken off');

        $this->panel->reset();
        try {
            $provider->createClient([], new ClientSpec('amir_1'));
            self::fail('a user reaches inbounds through squads');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->panel->calls());
        }
    }

    /** A name taken (A019) is a refusal before anything was made; a 5xx after it went in (A023: made, its read back failed) may have been done. */
    public function testACreateRefusedOrFailedAfterItWentIn(): void
    {
        $provider = $this->provider([self::refusal(400, 'User username already exists', 'A019')]);
        try {
            $provider->createClient([self::DE], new ClientSpec('amir_1'));
            self::fail('the name is taken');
        } catch (RemnawaveApiException $e) {
            self::assertSame([400, 'A019'], [$e->httpStatus, $e->errorCode]);
            self::assertFalse($e->mayHaveBeenDone(), 'nothing made: the next name is tried');
        }

        $provider = $this->provider([self::refusal(500, 'User creation successful, but failed to get created user with inbounds.', 'A023')]);
        try {
            $provider->createClient([self::DE], new ClientSpec('amir_1'));
            self::fail('its read back failed');
        } catch (ProviderException $e) {
            self::assertTrue($e->mayHaveBeenDone(), 'made: never made again under the next name');
        }
    }

    /** The user read, then one PATCH of what the shop sets (update-user.command.ts): fields left out — its squads, link — stay. */
    public function testAnUpdateReadsTheUserThenPatchesItsLimitTermAndComment(): void
    {
        $provider = $this->provider([self::answer(self::user()), self::answer(self::user(['trafficLimitBytes' => 50 * self::GIB, 'expireAt' => '2027-01-15T10:00:00.000Z']))]);

        $client = $provider->updateClient(new ClientSpec('amir_1', 50 * self::GIB, Expiry::at(new \DateTimeImmutable('2027-01-15 10:00:00 UTC')), ipLimit: 3, telegramId: 42, comment: '42 | amir'));

        self::assertSame(['GET /api/users/by-username/amir_1', 'PATCH /api/users'], $this->panel->calls());
        self::assertBody([
            'id' => 7,
            'trafficLimitBytes' => 50 * self::GIB,
            'trafficLimitStrategy' => 'NO_RESET',
            'hwidDeviceLimit' => 3,
            'expireAt' => '2027-01-15T10:00:00.000Z',
            'description' => '42 | amir',
            'telegramId' => 42,
        ], $this->panel->params(1), 'by its id alone; no status for one that runs; its squads as they are, its devices as the HWID limit');
        self::assertSame([50 * self::GIB, '2027-01-15 10:00:00'], [$client->totalBytes, $client->expiry->deadline()?->format('Y-m-d H:i:s')]);
    }

    /** 2.x addresses a user by its uuid (2.1 and 2.2 take no other in a PATCH): its paths and its body carry that. */
    public function testA2xUserIsAddressedByItsUuid(): void
    {
        $legacy = self::legacy();
        $provider = $this->provider([
            self::answer($legacy), self::answer($legacy),
            self::answer($legacy), self::answer(['status' => 'DISABLED'] + $legacy),
            self::answer(['status' => 'DISABLED'] + $legacy), self::answer($legacy),
            self::answer($legacy), self::answer($legacy),
            self::answer($legacy), self::answer($legacy),
            self::answer($legacy), self::answer(['isDeleted' => true]),
        ]);

        $provider->updateClient(new ClientSpec('amir_1', 10 * self::GIB, Expiry::at(new \DateTimeImmutable('2027-01-01 00:00:00 UTC'))));
        $provider->setClientEnabled('amir_1', false);
        $provider->setClientEnabled('amir_1', true);
        $provider->resetClientTraffic('amir_1');
        $provider->rotateClientCredentials('amir_1');
        $provider->deleteClient('amir_1');

        self::assertSame([
            'GET /api/users/by-username/amir_1', 'PATCH /api/users',
            'GET /api/users/by-username/amir_1', 'POST /api/users/' . self::UUID . '/actions/disable',
            'GET /api/users/by-username/amir_1', 'POST /api/users/' . self::UUID . '/actions/enable',
            'GET /api/users/by-username/amir_1', 'POST /api/users/' . self::UUID . '/actions/reset-traffic',
            'GET /api/users/by-username/amir_1', 'POST /api/users/' . self::UUID . '/actions/revoke',
            'GET /api/users/by-username/amir_1', 'DELETE /api/users/' . self::UUID,
        ], $this->panel->calls());
        self::assertSame(self::UUID, $this->panel->params(1)['uuid']);
        self::assertArrayNotHasKey('id', $this->panel->params(1), 'its uuid wins: 2.1 and 2.2 know no other');
    }

    /** 3.x gave users numeric ids and took their uuids away: every action's path takes the id. */
    public function testA3xUserIsAddressedByItsId(): void
    {
        $provider = $this->provider([
            self::answer(self::user(['id' => 4021, 'status' => 'DISABLED'])), self::answer(self::user(['id' => 4021])),
            self::answer(self::user(['id' => 4021])), self::answer(self::user(['id' => 4021])),
            self::answer(self::user(['id' => 4021])), self::answer(self::user(['id' => 4021, 'shortUuid' => 'Nw8RtY2pLk5Vb3Xz'])),
            self::answer(self::user(['id' => 4021])), new Response(204),
        ]);

        $provider->setClientEnabled('amir_1', true);
        $provider->resetClientTraffic('amir_1');
        $provider->rotateClientCredentials('amir_1');
        $provider->deleteClient('amir_1');

        self::assertSame([
            'GET /api/users/by-username/amir_1', 'POST /api/users/4021/actions/enable',
            'GET /api/users/by-username/amir_1', 'POST /api/users/4021/actions/reset-traffic',
            'GET /api/users/by-username/amir_1', 'POST /api/users/4021/actions/revoke',
            'GET /api/users/by-username/amir_1', 'DELETE /api/users/4021',
        ], $this->panel->calls());
        foreach ([1, 3, 5, 7] as $i) {
            self::assertSame('', (string) $this->panel->request($i)->getBody(), "call #{$i} takes no body");
        }
    }

    public function testAUserWithoutAnIdIsNoUserOfASupportedPanel(): void
    {
        $user = self::user();
        unset($user['id']);
        $provider = $this->provider([self::answer($user)]);

        try {
            $provider->deleteClient('amir_1');
            self::fail('a Remnawave before 2.1, or not Remnawave');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(['GET /api/users/by-username/amir_1'], $this->panel->calls(), 'nothing deleted');
            self::assertStringContainsString('2.1', $e->hint);
        }
    }

    /**
     * endFor(): Remnawave refuses a deadline not in the future (update-user.command.ts). One that is not a minute away cuts
     * the user's own deadline to the soonest the panel takes, when it ends later; when it has passed, or ends sooner, the
     * deadline is left out — never a 400 the panel would answer again at every try.
     */
    public function testAnUpdatesDeadlineIsNeverOneThePanelRefusesAsPast(): void
    {
        $cases = [
            'a past deadline, the user\'s own later' => ['2026-12-01T00:00:00.000Z', Expiry::at(new \DateTimeImmutable('2026-11-14 10:00:00 UTC')), '2026-11-15T10:01:00.000Z'],
            'a past deadline, the user\'s own passed' => ['2026-11-01T00:00:00.000Z', Expiry::at(new \DateTimeImmutable('2026-11-14 10:00:00 UTC')), null],
            'half a minute away, the user\'s own later' => ['2026-12-01T00:00:00.000Z', Expiry::at(new \DateTimeImmutable('2026-11-15 10:00:30 UTC')), '2026-11-15T10:01:00.000Z'],
            'half a minute away, the user\'s own sooner' => ['2026-11-15T10:00:45.000Z', Expiry::at(new \DateTimeImmutable('2026-11-15 10:00:30 UTC')), null],
            'two minutes away' => ['2026-11-01T00:00:00.000Z', Expiry::at(new \DateTimeImmutable('2026-11-15 10:02:00 UTC')), '2026-11-15T10:02:00.000Z'],
            'a term to start' => ['2026-11-01T00:00:00.000Z', Expiry::afterFirstUse(self::MONTH), '2026-12-15T10:00:00.000Z'],
            'none' => ['2026-12-01T00:00:00.000Z', Expiry::never(), '2099-12-31T00:00:00.000Z'],
        ];

        foreach ($cases as $case => [$had, $expiry, $sent]) {
            $provider = $this->provider([self::answer(self::user(['expireAt' => $had])), self::answer(self::user())]);

            $provider->updateClient(new ClientSpec('amir_1', 20 * self::GIB, $expiry));

            self::assertSame($sent, $this->panel->params(1)['expireAt'] ?? null, $case);
            self::assertSame('NO_RESET', $this->panel->params(1)['trafficLimitStrategy'], "{$case}: the quota is once a term");
        }
    }

    /**
     * users.service.ts updateUser: `status: ACTIVE` turns any user active. Kept on, a switched-off user is sent back on, and
     * one out of time or traffic when its new term runs (traffic left, a deadline a minute away); otherwise the panel's own
     * checks keep it as it is.
     */
    public function testARenewedUserRunsAgainAsRemnawaveSwitchesIt(): void
    {
        $later = Expiry::at(new \DateTimeImmutable('2026-12-15 10:00:00 UTC'));
        $traffic = static fn(int $used): array => ['usedTrafficBytes' => $used, 'lifetimeUsedTrafficBytes' => $used, 'onlineAt' => null, 'firstConnectedAt' => null, 'lastConnectedNodeUuid' => null];
        $cases = [
            'expired, renewed' => [['status' => 'EXPIRED', 'expireAt' => '2026-11-14T10:00:00.000Z'], new ClientSpec('amir_1', 30 * self::GIB, $later), 'ACTIVE'],
            'expired, renewed with no end' => [['status' => 'EXPIRED', 'expireAt' => '2026-11-14T10:00:00.000Z'], new ClientSpec('amir_1', 30 * self::GIB), 'ACTIVE'],
            'expired, a term to start' => [['status' => 'EXPIRED', 'expireAt' => '2026-11-14T10:00:00.000Z'], new ClientSpec('amir_1', 30 * self::GIB, Expiry::afterFirstUse(self::MONTH)), 'ACTIVE'],
            'limited, given traffic' => [['status' => 'LIMITED', 'trafficLimitBytes' => 10 * self::GIB, 'userTraffic' => $traffic(10 * self::GIB)], new ClientSpec('amir_1', 40 * self::GIB, $later), 'ACTIVE'],
            'limited, given no limit' => [['status' => 'LIMITED', 'trafficLimitBytes' => 10 * self::GIB, 'userTraffic' => $traffic(10 * self::GIB)], new ClientSpec('amir_1', 0, $later), 'ACTIVE'],
            'limited, still over' => [['status' => 'LIMITED', 'trafficLimitBytes' => 10 * self::GIB, 'userTraffic' => $traffic(12 * self::GIB)], new ClientSpec('amir_1', 11 * self::GIB, $later), null],
            'expired, a deadline already past' => [['status' => 'EXPIRED', 'expireAt' => '2026-11-14T10:00:00.000Z'], new ClientSpec('amir_1', 30 * self::GIB, Expiry::at(new \DateTimeImmutable('2026-11-15 09:00:00 UTC'))), null],
            'expired, half a minute' => [['status' => 'EXPIRED', 'expireAt' => '2026-11-14T10:00:00.000Z'], new ClientSpec('amir_1', 30 * self::GIB, Expiry::at(new \DateTimeImmutable('2026-11-15 10:00:30 UTC'))), null],
            'switched off, switched on' => [['status' => 'DISABLED', 'expireAt' => '2026-11-14T10:00:00.000Z'], new ClientSpec('amir_1', 30 * self::GIB, Expiry::at(new \DateTimeImmutable('2026-11-15 09:00:00 UTC'))), 'ACTIVE'],
            'active, kept on' => [['status' => 'ACTIVE'], new ClientSpec('amir_1', 30 * self::GIB, $later), null],
        ];

        foreach ($cases as $case => [$had, $spec, $status]) {
            $provider = $this->provider([self::answer(self::user($had)), self::answer(self::user())]);

            $provider->updateClient($spec);

            self::assertSame(['GET /api/users/by-username/amir_1', 'PATCH /api/users'], $this->panel->calls(), $case);
            self::assertSame($status, $this->panel->params(1)['status'] ?? null, $case);
        }
    }

    /**
     * Kept off: sent DISABLED. A PATCH switches off only an active user, and a higher limit would put a limited one back on:
     * one out of time or traffic is switched off by its own action first — one switched off meanwhile (A029) counts.
     */
    public function testAUserKeptOffStaysOff(): void
    {
        foreach (['ACTIVE', 'DISABLED'] as $status) {
            $provider = $this->provider([self::answer(self::user(['status' => $status])), self::answer(self::user(['status' => 'DISABLED']))]);

            $client = $provider->updateClient(new ClientSpec('amir_1', 50 * self::GIB, enabled: false));

            self::assertSame(['GET /api/users/by-username/amir_1', 'PATCH /api/users'], $this->panel->calls(), $status);
            self::assertSame('DISABLED', $this->panel->params(1)['status'], $status);
            self::assertFalse($client->enabled);
        }

        foreach (['LIMITED' => self::user(['status' => 'DISABLED']), 'EXPIRED' => self::refusal(400, 'User already disabled', 'A029')] as $status => $action) {
            $provider = $this->provider([self::answer(self::user(['status' => $status])), is_array($action) ? self::answer($action) : $action, self::answer(self::user(['status' => 'DISABLED']))]);

            $provider->updateClient(new ClientSpec('amir_1', 50 * self::GIB, Expiry::at(new \DateTimeImmutable('2026-12-15 10:00:00 UTC')), enabled: false));

            self::assertSame(['GET /api/users/by-username/amir_1', 'POST /api/users/7/actions/disable', 'PATCH /api/users'], $this->panel->calls(), $status);
            self::assertSame(['DISABLED', 50 * self::GIB, '2026-12-15T10:00:00.000Z'], [$this->panel->params(2)['status'], $this->panel->params(2)['trafficLimitBytes'], $this->panel->params(2)['expireAt']], "{$status}: its new terms, off");
        }

        // Another refusal of the switch stops the update: nothing is patched on a user still running.
        $provider = $this->provider([self::answer(self::user(['status' => 'LIMITED'])), self::refusal(500, 'Disable user error', 'A028')]);
        try {
            $provider->updateClient(new ClientSpec('amir_1', enabled: false));
            self::fail('the switch failed');
        } catch (RemnawaveApiException $e) {
            self::assertSame('A028', $e->errorCode);
            self::assertSame(['GET /api/users/by-username/amir_1', 'POST /api/users/7/actions/disable'], $this->panel->calls());
        }
    }

    public function testAnUpdateOfAUserThePanelDoesNotHaveIsNotFound(): void
    {
        $provider = $this->provider([self::refusal(404, 'User with specified params not found', 'A063')]);

        try {
            $provider->updateClient(new ClientSpec('amir_1'));
            self::fail('no such user');
        } catch (NotFoundException $e) {
            self::assertSame(['GET /api/users/by-username/amir_1'], $this->panel->calls(), 'nothing patched');
            self::assertFalse($e->mayHaveBeenDone());
        }
    }

    public function testSwitchingIsANoOpWhenTheUserIsAsAskedAlready(): void
    {
        foreach (['off, asked off' => ['DISABLED', false], 'on, asked on' => ['ACTIVE', true], 'limited, asked on' => ['LIMITED', true], 'expired, asked on' => ['EXPIRED', true]] as $case => [$status, $enabled]) {
            $provider = $this->provider([self::answer(self::user(['status' => $status]))]);

            $provider->setClientEnabled('amir_1', $enabled);

            self::assertSame(['GET /api/users/by-username/amir_1'], $this->panel->calls(), "{$case}: «on» is any status but DISABLED");
        }
    }

    /** Its own actions take any status: off is off, whatever it was; on is active until the panel's next review says otherwise. */
    public function testSwitchingIsTheUsersOwnActionAndOneSwitchedMeanwhileCounts(): void
    {
        foreach (['ACTIVE' => false, 'LIMITED' => false, 'EXPIRED' => false, 'DISABLED' => true] as $status => $enabled) {
            $provider = $this->provider([self::answer(self::user(['status' => $status])), self::answer(self::user(['status' => $enabled ? 'ACTIVE' : 'DISABLED']))]);

            $provider->setClientEnabled('amir_1', $enabled);

            self::assertSame(['GET /api/users/by-username/amir_1', 'POST /api/users/7/actions/' . ($enabled ? 'enable' : 'disable')], $this->panel->calls(), $status);
            self::assertSame('', (string) $this->panel->request(1)->getBody());
        }

        $provider = $this->provider([self::answer(self::user(['status' => 'DISABLED'])), self::refusal(400, 'User already enabled', 'A030')]);
        $provider->setClientEnabled('amir_1', true);
        self::assertCount(2, $this->panel->calls(), 'switched on meanwhile: done');

        $provider = $this->provider([self::answer(self::user(['status' => 'DISABLED'])), self::refusal(500, 'Enable user error', 'A031')]);
        $this->expectException(RemnawaveApiException::class);
        $provider->setClientEnabled('amir_1', true);
    }

    /** actions/revoke: a new short id — a new link —, uuid and passwords; every link and config handed out before stops. */
    public function testRotatingRevokesTheSubscriptionForANewLink(): void
    {
        $provider = $this->provider([self::answer(self::user()), self::answer(self::user(['shortUuid' => 'Nw8RtY2pLk5Vb3Xz', 'subscriptionUrl' => 'https://sub.example.com/Nw8RtY2pLk5Vb3Xz']))]);

        $client = $provider->rotateClientCredentials('amir_1');

        self::assertSame(['GET /api/users/by-username/amir_1', 'POST /api/users/7/actions/revoke'], $this->panel->calls());
        self::assertSame('', (string) $this->panel->request(1)->getBody(), 'no body: Remnawave mints the new short id itself');
        self::assertSame('https://sub.example.com/Nw8RtY2pLk5Vb3Xz', $client->subscriptionUrl);
    }

    /** DELETE is for good (204 on 3.x): its name is free again. */
    public function testDeletingReadsTheUserThenDeletesItAndAMissingUserIsNotFound(): void
    {
        $provider = $this->provider([self::answer(self::user()), new Response(204), self::refusal(404, 'User with specified params not found', 'A063')]);

        $provider->deleteClient('amir_1');
        self::assertSame(['GET /api/users/by-username/amir_1', 'DELETE /api/users/7'], $this->panel->calls());

        $this->panel->reset();
        try {
            $provider->deleteClient('amir_1');
            self::fail('no such user');
        } catch (NotFoundException) {
            self::assertSame(['GET /api/users/by-username/amir_1'], $this->panel->calls());
        }
    }

    /** reset-traffic: usage 0; a user limited for its traffic active again — nothing to send back. */
    public function testResettingTheTrafficIsTheUsersOwnAction(): void
    {
        $provider = $this->provider([self::answer(self::user(['status' => 'LIMITED'])), self::answer(self::user())]);

        $provider->resetClientTraffic('amir_1');

        self::assertSame(['GET /api/users/by-username/amir_1', 'POST /api/users/7/actions/reset-traffic'], $this->panel->calls());
    }

    /** The user's HWID limit is its devices: a number as it is (0 for none), null — the panel's fallback — unsaid. */
    public function testTheHwidLimitIsTheClientsDevices(): void
    {
        foreach ([[4, 4], [0, 0], [null, null]] as [$limit, $devices]) {
            $provider = $this->provider([self::answer(self::user(['hwidDeviceLimit' => $limit]))]);

            self::assertSame($devices, $provider->findClient('amir_1')?->ipLimit, var_export($limit, true));
        }
    }

    public function testFindClientReadsTheUserByItsNameAndIsNullForOneThePanelDoesNotHave(): void
    {
        $provider = $this->provider([self::answer(self::user(['username' => 'Amir_1'])), self::refusal(404, 'User with specified params not found', 'A063'), self::refusal(404, 'User not found', 'A025')]);

        $client = $provider->findClient('Amir_1');
        self::assertSame(['Amir_1', null], [$client?->name, $client?->online], 'not asked: unknown');
        self::assertSame(['GET /api/users/by-username/Amir_1'], $this->panel->calls(), 'the name as it is written');

        self::assertNull($provider->findClient('amir_1'));
        self::assertNull($provider->findClient('amir_1'));

        // A name with what a path cannot carry as it is goes encoded.
        $provider = $this->provider([self::refusal(404, 'User with specified params not found', 'A063')]);
        self::assertNull($provider->findClient('ali reza/1'));
        self::assertSame('/api/users/by-username/ali%20reza%2F1', $this->panel->request(0)->getUri()->getPath());
    }

    /** A refusal of the token is no missing user: it stands — never a name ProvisioningService takes for free, nor for taken. */
    public function testFindClientLetsATokensRefusalStand(): void
    {
        $provider = $this->provider([self::json(403, ['message' => 'Forbidden resource', 'error' => 'Forbidden', 'statusCode' => 403])]);

        $this->expectException(AuthenticationException::class);
        $provider->findClient('amir_1');
    }

    /**
     * An answer without the user asked for — something in front of the panel answering `{"response":{}}`, another user —
     * is no user: read as one, it would be a client with no name, limit or end that a sync writes onto the service.
     */
    public function testAnAnswerWithoutTheUserAskedForIsNoUser(): void
    {
        foreach (['an empty answer' => [], 'another user' => self::user(['username' => 'sara_2']), 'the name in another case' => self::user(['username' => 'Amir_1'])] as $case => $answer) {
            $provider = $this->provider([self::answer($answer)]);
            try {
                $provider->findClient('amir_1');
                self::fail("{$case}: not the user asked for");
            } catch (UnexpectedResponseException $e) {
                self::assertSame(200, $e->httpStatus, $case);
                self::assertTrue($e->unavailable(), "{$case}: the panel is not answering as itself");
            }
        }

        // Nothing is written on what was not the user.
        foreach (['update' => static fn(ProviderInterface $p) => $p->updateClient(new ClientSpec('amir_1')), 'switch' => static fn(ProviderInterface $p) => $p->setClientEnabled('amir_1', false), 'delete' => static fn(ProviderInterface $p) => $p->deleteClient('amir_1'), 'rotate' => static fn(ProviderInterface $p) => $p->rotateClientCredentials('amir_1'), 'reset' => static fn(ProviderInterface $p) => $p->resetClientTraffic('amir_1')] as $case => $call) {
            $provider = $this->provider([self::answer([])]);
            try {
                $call($provider);
                self::fail("{$case}: not the user asked for");
            } catch (UnexpectedResponseException) {
                self::assertSame(['GET /api/users/by-username/amir_1'], $this->panel->calls(), $case);
            }
        }
    }

    /** Presence is Remnawave's own window: traffic seen in the last 30 seconds (users.repository.ts getUserOnlineStats). */
    public function testPresenceIsTrafficSeenInTheLast30Seconds(): void
    {
        $seen = static fn(?string $at): array => ['userTraffic' => ['usedTrafficBytes' => 0, 'lifetimeUsedTrafficBytes' => 0, 'onlineAt' => $at, 'firstConnectedAt' => null, 'lastConnectedNodeUuid' => null]];
        $provider = $this->provider([
            self::answer(self::user($seen('2026-11-15T09:59:40.000Z'))),
            self::answer(self::user($seen('2026-11-15T09:59:30.000Z'))),
            self::answer(self::user($seen('2026-11-15T09:59:29.000Z'))),
            self::answer(self::user($seen(null))),
            self::answer(self::user($seen('2026-11-15T09:59:40.000Z'))),
        ]);

        $read = [];
        foreach ([true, true, true, true, false] as $presence) {
            $read[] = $provider->findClient('amir_1', $presence);
        }
        [$twenty, $thirty, $gone, $never, $unasked] = $read;

        self::assertTrue($twenty?->online, '20 seconds ago');
        self::assertTrue($thirty?->online, '30 seconds ago, the window\'s edge');
        self::assertFalse($gone?->online, '31 seconds ago');
        self::assertSame('2026-11-15 09:59:29', $gone->lastOnlineAt?->format('Y-m-d H:i:s'));
        self::assertFalse($never?->online, 'never seen');
        self::assertNull($unasked?->online, 'not asked');
        self::assertCount(5, $this->panel->calls(), 'presence comes with the user: no other call');
    }

    public function testTheUsersAreListed1000AtATimeUntilTheTotal(): void
    {
        $page = static fn(int $from, int $count, int $total): Response => self::answer(['users' => array_map(static fn(int $i): array => self::user(['id' => $i, 'username' => sprintf('user_%04d', $i)]), range($from, $from + $count - 1)), 'total' => $total]);
        $provider = $this->provider([$page(1, 1000, 2002), $page(1001, 1000, 2002), $page(2001, 2, 2002)]);

        $clients = $provider->listClients();

        self::assertSame(['GET /api/users', 'GET /api/users', 'GET /api/users'], $this->panel->calls());
        self::assertSame(['start=0&size=1000', 'start=1000&size=1000', 'start=2000&size=1000'], array_map(fn(int $i): string => $this->panel->request($i)->getUri()->getQuery(), [0, 1, 2]));
        self::assertCount(2002, $clients);
        self::assertSame(['user_0001', null, 'https://sub.example.com/' . self::SHORT], [$clients[0]->name, $clients[0]->ipLimit, $clients[0]->subscriptionUrl], 'the listing carries all a user\'s own read does');
    }

    public function testTheTotalOrAShortPageEndsTheListing(): void
    {
        $full = array_map(static fn(int $i): array => self::user(['id' => $i, 'username' => "user_{$i}"]), range(1, 1000));
        $provider = $this->provider([self::answer(['users' => $full, 'total' => 1000])]);
        self::assertCount(1000, $provider->listClients());
        self::assertCount(1, $this->panel->calls(), 'a full page that is the whole total asks for no other');

        $provider = $this->provider([self::answer(['users' => [self::user()]])]);
        self::assertCount(1, $provider->listClients());
        self::assertCount(1, $this->panel->calls(), 'no total: a short page is the last');

        $provider = $this->provider([self::answer(['users' => $full]), self::answer(['users' => [], 'total' => 1000])]);
        self::assertCount(1000, $provider->listClients());
        self::assertCount(2, $this->panel->calls(), 'no total on a full page: the next, empty, ends it');
    }

    public function testAPanelPast50PagesIsNotListedAtOnce(): void
    {
        $full = self::answer(['users' => array_map(static fn(int $i): array => ['id' => $i, 'username' => "user_{$i}", 'status' => 'ACTIVE', 'expireAt' => '2099-12-31T00:00:00.000Z'], range(1, 1000))]);
        $provider = $this->provider(array_fill(0, 60, $full));

        try {
            $provider->listClients();
            self::fail('fifty pages and no end');
        } catch (UnsupportedOperationException $e) {
            self::assertCount(50, $this->panel->calls(), 'at most 50,000 users read at once');
            self::assertSame('start=49000&size=1000', $this->panel->request(49)->getUri()->getQuery());
            self::assertStringContainsString('50000', $e->getMessage());
        }
    }

    /** Remnawave lists its newest first: a user made meanwhile shifts another onto the next page — one client, the last reading. */
    public function testAUserListedTwiceIsOneClient(): void
    {
        $first = array_map(static fn(int $i): array => self::user(['id' => $i, 'username' => "user_{$i}"]), range(1, 1000));
        $provider = $this->provider([
            self::answer(['users' => $first, 'total' => 1001]),
            self::answer(['users' => [self::user(['id' => 1000, 'username' => 'user_1000', 'userTraffic' => ['usedTrafficBytes' => 7, 'lifetimeUsedTrafficBytes' => 7, 'onlineAt' => null, 'firstConnectedAt' => null, 'lastConnectedNodeUuid' => null]]), self::user(['id' => 1001, 'username' => 'user_1001'])], 'total' => 1002]),
        ]);

        $clients = array_column(array_map(static fn($client): array => ['client' => $client, 'name' => $client->name], $provider->listClients()), 'client', 'name');

        self::assertCount(1001, $clients);
        self::assertSame(7, $clients['user_1000']->downloadBytes);
    }

    /** A short id from the link's parts: GET by-short-uuid — the token carries the lookup, a few parts at most, no listing. */
    public function testASubscriptionLinksShortIdIsLookedUp(): void
    {
        $provider = $this->provider([self::answer(self::user())]);

        $client = $provider->findClientByLink(self::link('سرویسم: https://sub.example.com/' . self::SHORT));

        self::assertSame('amir_1', $client?->name);
        self::assertSame(['GET /api/users/by-short-uuid/' . self::SHORT], $this->panel->calls());
        self::assertSame('rw.example', $this->panel->request(0)->getUri()->getHost(), 'asked of the panel, not of the host in the link');
        self::assertSame('Bearer ' . self::TOKEN, $this->panel->request(0)->getHeaderLine('Authorization'), 'an authenticated read: no anonymous call');
    }

    public function testALinkNoUserHasIsNoneAndOnlyAFewOfItsPartsAreAsked(): void
    {
        $ids = ['AaAaAaAaAaAaAaA1', 'BbBbBbBbBbBbBbB2', 'CcCcCcCcCcCcCcC3', 'DdDdDdDdDdDdDdD4', 'EeEeEeEeEeEeEeE5'];
        $gone = static fn(): Response => self::refusal(404, 'User with specified params not found', 'A063');
        $provider = $this->provider([$gone(), $gone(), $gone(), $gone(), $gone()]);

        self::assertNull($provider->findClientByLink(self::link(implode(' ', array_map(static fn(string $id): string => "https://sub.example.com/{$id}", $ids)))));
        self::assertCount(4, $this->panel->calls(), 'at most four: each is a request; no config to match against the listing');

        // The next part is asked when one is no user's; a part no short id looks like (a format's file name) is not asked.
        $provider = $this->provider([$gone(), self::answer(self::user())]);
        self::assertSame('amir_1', $provider->findClientByLink(self::link('https://sub.example.com/x/AaAaAaAaAaAaAaA1 https://sub.example.com/' . self::SHORT . '/sub.json'))?->name);
        self::assertSame(['GET /api/users/by-short-uuid/AaAaAaAaAaAaAaA1', 'GET /api/users/by-short-uuid/' . self::SHORT], $this->panel->calls());

        // An answer without a user is none of them either: the next part is asked.
        $provider = $this->provider([self::answer([]), self::answer(self::user())]);
        self::assertSame('amir_1', $provider->findClientByLink(self::link('https://sub.example.com/x/AaAaAaAaAaAaAaA1 https://sub.example.com/' . self::SHORT))?->name);
        self::assertSame(['GET /api/users/by-short-uuid/AaAaAaAaAaAaAaA1', 'GET /api/users/by-short-uuid/' . self::SHORT], $this->panel->calls());

        $provider = $this->provider([self::answer([])]);
        self::assertNull($provider->findClientByLink(self::link('https://sub.example.com/' . self::SHORT)), 'never a client with no name');
    }

    /** A config's credentials against every user's: the VLESS uuid (its third group a host's route id may replace), Trojan's, Shadowsocks' (2022's in base64). */
    public function testAConfigsCredentialsAreMatchedAgainstTheUsers(): void
    {
        $routed = substr(self::VLESS, 0, 14) . '7777' . substr(self::VLESS, 18);
        foreach ([
            'a VLESS config' => 'vless://' . strtoupper(self::VLESS) . '@de1.example.com:443?security=reality&type=tcp#amir_1',
            'a VLESS config through a host route' => "vless://{$routed}@de1.example.com:443?security=reality#amir_1",
            'a Trojan config' => 'trojan://' . self::TROJAN . '@de1.example.com:443?security=tls#amir_1',
            'a Shadowsocks config' => 'ss://' . base64_encode('chacha20-ietf-poly1305:' . self::SS) . '@de1.example.com:8388#amir_1',
            'a Shadowsocks 2022 config' => 'ss://' . rawurlencode('2022-blake3-aes-256-gcm:c2VydmVyLWtleS0zMi1ieXRlcy1sb25nLTAxMjM0NTY=:' . base64_encode(self::SS)) . '@de1.example.com:8388#amir_1',
        ] as $case => $text) {
            $provider = $this->provider([
                self::answer(['users' => [self::user(['id' => 3, 'username' => 'sara_2', 'vlessUuid' => '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d', 'trojanPassword' => 'SomeoneElse12345', 'ssPassword' => 'SomeoneElse67890']), self::user()], 'total' => 2]),
                self::answer(self::user()),
            ]);

            self::assertSame('amir_1', $provider->findClientByLink(self::link($text))?->name, $case);
            self::assertSame(['GET /api/users', 'GET /api/users/by-username/amir_1'], $this->panel->calls(), "{$case}: the match read on its own");
            self::assertSame('start=0&size=1000', $this->panel->request(0)->getUri()->getQuery());
        }

        $provider = $this->provider([self::answer(['users' => [self::user()], 'total' => 1])]);
        self::assertNull($provider->findClientByLink(self::link('trojan://NobodysPassword99@de1.example.com:443#x')));
        self::assertSame(['GET /api/users'], $this->panel->calls(), 'no one\'s: none');
    }

    public function testAUserIsTheContractsClient(): void
    {
        $provider = $this->provider([
            self::answer(self::user(['status' => 'LIMITED', 'trafficLimitBytes' => 30 * self::GIB, 'userTraffic' => ['usedTrafficBytes' => 31 * self::GIB, 'lifetimeUsedTrafficBytes' => 90 * self::GIB, 'onlineAt' => '2026-11-14T13:00:00+03:30', 'firstConnectedAt' => '2026-11-01T00:00:00.000Z', 'lastConnectedNodeUuid' => null]])),
            self::answer(self::user(['status' => 'EXPIRED', 'expireAt' => '2026-11-14T09:30:00.123Z', 'userTraffic' => ['usedTrafficBytes' => 0, 'lifetimeUsedTrafficBytes' => 0, 'onlineAt' => 'never', 'firstConnectedAt' => null, 'lastConnectedNodeUuid' => null]])),
            self::answer(self::user(['status' => 'DISABLED', 'expireAt' => null, 'trafficLimitBytes' => -1])),
            self::answer(self::legacy21()),
        ]);

        $limited = $provider->findClient('amir_1');
        self::assertNotNull($limited);
        self::assertSame([true, 0, 31 * self::GIB, 30 * self::GIB], [$limited->enabled, $limited->uploadBytes, $limited->downloadBytes, $limited->totalBytes], 'one total, as download; the term\'s usage, not its lifetime; limited is the panel\'s word, not the shop\'s switch');
        self::assertSame(['2026-11-14 09:30:00', 'UTC'], [$limited->lastOnlineAt?->format('Y-m-d H:i:s'), $limited->lastOnlineAt?->getTimezone()->getName()]);

        $expired = $provider->findClient('amir_1');
        self::assertSame([true, '2026-11-14 09:30:00', null], [$expired?->enabled, $expired?->expiry->deadline()?->format('Y-m-d H:i:s'), $expired?->lastOnlineAt], 'an unreadable moment is none');

        $off = $provider->findClient('amir_1');
        self::assertSame([false, null, 0], [$off?->enabled, $off?->expiry->deadline(), $off?->totalBytes], 'no deadline at all is none');

        // 2.1 and 2.2 keep the traffic and the last connection on the user itself.
        $old = $provider->findClient('amir_1');
        self::assertSame([5 * self::GIB, '2026-11-15 09:59:50'], [$old?->downloadBytes, $old?->lastOnlineAt?->format('Y-m-d H:i:s')]);
    }

    public function testTheLinkIsThePanelsOrTheServersPrefixBeforeTheShortId(): void
    {
        $provider = $this->provider([
            self::answer(self::user(['subscriptionUrl' => 'https://sub.example.com:2096/' . self::SHORT])),
            self::answer(self::user(['subscriptionUrl' => '/api/sub/' . self::SHORT])),
            self::answer(self::user(['subscriptionUrl' => ''])),
        ]);
        self::assertSame(['https://sub.example.com:2096/' . self::SHORT, self::BASE . '/api/sub/' . self::SHORT, null], $this->links($provider, 3), 'the panel\'s subscription domain as it is; a path made absolute on the panel; none');

        $provider = $this->provider([self::answer(self::user()), self::answer(self::user(['subscriptionUrl' => '', 'shortUuid' => 'Nw8RtY2pLk5Vb3Xz'])), self::answer(self::user(['shortUuid' => '']))], ['subscription_url' => 'https://get.example.com/s/']);
        self::assertSame(['https://get.example.com/s/' . self::SHORT, 'https://get.example.com/s/Nw8RtY2pLk5Vb3Xz', 'https://sub.example.com/' . self::SHORT], $this->links($provider, 3), 'the server\'s prefix before the short id; with no short id, the panel\'s link');
    }

    /**
     * The links of the next `$count` reads of the user.
     *
     * @return list<string|null>
     */
    private function links(ProviderInterface $provider, int $count): array
    {
        $links = [];
        for ($i = 0; $i < $count; $i++) {
            $links[] = $provider->findClient('amir_1')?->subscriptionUrl;
        }

        return $links;
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

        $server = new Server(['name' => 'RW', 'driver' => 'remnawave', 'base_url' => self::BASE, 'api_token' => self::TOKEN, 'meta' => $meta]);

        return (new RemnawaveDriver())->connect($server, new PanelHttp($this->panel->client(), new NullLogger()));
    }

    /**
     * Two bodies alike whatever their keys' order — every key and its value's type.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private static function assertBody(array $expected, array $actual, string $message = ''): void
    {
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual, $message);
    }

    /**
     * A user as Remnawave 3.x answers it (libs/contract/models/users.schema.ts, extended-users.schema.ts): active, 30 GB, a
     * month left, in the German squad, its link on the panel's subscription domain.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function user(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'shortUuid' => self::SHORT,
            'username' => 'amir_1',
            'status' => 'ACTIVE',
            'trafficLimitBytes' => 30 * self::GIB,
            'trafficLimitStrategy' => 'NO_RESET',
            'expireAt' => '2026-12-15T10:00:00.000Z',
            'telegramId' => 42,
            'email' => null,
            'description' => '42 | amir',
            'tag' => null,
            'hwidDeviceLimit' => null,
            'externalSquadUuid' => null,
            'trojanPassword' => self::TROJAN,
            'vlessUuid' => self::VLESS,
            'ssPassword' => self::SS,
            'lastTriggeredThreshold' => 0,
            'subRevokedAt' => null,
            'lastTrafficResetAt' => null,
            'createdAt' => '2026-11-15T10:00:00.000Z',
            'updatedAt' => '2026-11-15T10:00:00.000Z',
            'subscriptionUrl' => 'https://sub.example.com/' . self::SHORT,
            'activeInternalSquads' => [['uuid' => self::DE, 'name' => 'Germany']],
            'userTraffic' => ['usedTrafficBytes' => 0, 'lifetimeUsedTrafficBytes' => 0, 'onlineAt' => null, 'firstConnectedAt' => null, 'lastConnectedNodeUuid' => null],
        ];
    }

    /** @return array<string, mixed> A user as Remnawave 2.3–2.8 answers it (backend-2.8.1 users.schema.ts): its uuid beside its id. */
    private static function legacy(): array
    {
        return ['uuid' => self::UUID] + self::user();
    }

    /** @return array<string, mixed> A user as Remnawave 2.1 answers it (backend-2.1.0 users.schema.ts): its uuid, no id, its traffic on itself. */
    private static function legacy21(): array
    {
        $user = ['uuid' => self::UUID, 'usedTrafficBytes' => 5 * self::GIB, 'lifetimeUsedTrafficBytes' => 9 * self::GIB, 'onlineAt' => '2026-11-15T09:59:50.000Z', 'firstConnectedAt' => '2026-11-01T00:00:00.000Z'] + self::user();
        unset($user['id'], $user['userTraffic']);

        return $user;
    }

    /**
     * An internal squad as GET /api/internal-squads lists it.
     *
     * @param list<string> $tags
     * @return array<string, mixed>
     */
    private static function squad(string $uuid, string $name, array $tags, int $members): array
    {
        return [
            'uuid' => $uuid,
            'viewPosition' => 1,
            'name' => $name,
            'tags' => [],
            'info' => ['membersCount' => $members, 'inboundsCount' => count($tags)],
            'inbounds' => array_map(static fn(string $tag): array => ['uuid' => 'd4e5f6a7-8b9c-4dae-8f01-3b4c5d6e7f80', 'profileUuid' => 'e5f6a7b8-9c0d-4ebf-9012-4c5d6e7f8091', 'tag' => $tag, 'type' => 'vless', 'network' => 'tcp', 'security' => 'reality', 'port' => 443, 'rawInbound' => null], $tags),
            'createdAt' => '2026-10-01T00:00:00.000Z',
            'updatedAt' => '2026-10-01T00:00:00.000Z',
        ];
    }

    /** @return array<string, mixed> GET /api/system/stats (get-stats.command.ts), its host's numbers. */
    private static function stats(): array
    {
        return [
            'cpu' => ['cores' => 4],
            'memory' => ['total' => 8 * self::GIB, 'free' => 5 * self::GIB, 'used' => 3 * self::GIB],
            'uptime' => 86400,
            'timestamp' => 1794736800000,
            'users' => ['statusCounts' => ['ACTIVE' => 120, 'DISABLED' => 3, 'LIMITED' => 2, 'EXPIRED' => 5], 'totalUsers' => 130],
            'onlineStats' => ['lastDay' => 80, 'lastWeek' => 110, 'neverOnline' => 4, 'onlineNow' => 12],
            'nodes' => ['totalOnline' => 2, 'totalBytesLifetime' => '1099511627776'],
        ];
    }

    /** Remnawave's answer to a call that went through: its `response` envelope. */
    private static function answer(mixed $response, int $status = 200): Response
    {
        return self::json($status, ['response' => $response]);
    }

    /** A refusal in HttpExceptionFilter's words. */
    private static function refusal(int $status, string $message, string $code): Response
    {
        return self::json($status, ['timestamp' => '2026-11-15T10:00:00.000Z', 'path' => '/api/users', 'message' => $message, 'errorCode' => $code]);
    }

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'], (string) json_encode($body));
    }

    private static function link(string $text): ClientLink
    {
        return ClientLink::find($text) ?? self::fail("No link in {$text}.");
    }
}
