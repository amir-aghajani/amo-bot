<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Rebecca;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\Drivers\Rebecca\RebeccaApiException;
use App\Modules\Providers\Drivers\Rebecca\RebeccaDriver;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\CoreState;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * The generic ProviderInterface on Rebecca, against answers shaped as its Go handlers write them (internal/app/api
 * users.go, services.go, system.go, admin_auth.go; internal/app/user dto.go): its services as the inbounds, one a user;
 * its terms — on hold, a deadline, none — as its validation and permission checks take them; its users listed for a
 * global admin, read one by one where the list leaves out what the shop needs; and a link's identifiers shown to its own
 * subscription route.
 */
final class RebeccaProviderTest extends TestCase
{
    private const KEY = 'rk_q8Xv2kL0bN7rT4yZ1aS6dF3gH9jK5mP2wE4tY6uI8o_';
    private const BASE = 'https://rb.example:8000';
    private const MONTH = 30 * 86400;
    private const GIB = 1024 ** 3;

    /** The user's credential key (internal/app/user/credentials.go: 16 random bytes, hex); its VLESS id is it as a UUID. */
    private const CREDENTIAL = 'b1c0e3f412344abc8def0123456789ab';
    private const UUID = 'b1c0e3f4-1234-4abc-8def-0123456789ab';

    /** The user's signed subscription token (links.go createSubscriptionToken): base64url("amir_1,1760262400") and 10 more. */
    private const TOKEN = 'YW1pcl8xLDE3NjAyNjI0MDA8f3Kq1ZbXy';

    /** Rebecca's answer to a write it took though it could not build the user's detail after it (users.go writeUserMutationDetail). */
    private const NO_DETAIL = 'User mutation completed, but the full user detail response could not be generated.';

    private FakePanel $panel;

    public function testTheConnectionTestReadsOneUser(): void
    {
        $provider = $this->provider([self::json(200, self::page([]))]);

        $provider->testConnection();

        self::assertSame(['GET /api/users'], $this->panel->calls());
        self::assertSame('limit=1', $this->panel->request(0)->getUri()->getQuery());

        $provider = $this->provider([self::json(401, ['detail' => 'Could not validate credentials'])]);
        $this->expectException(AuthenticationException::class);
        $provider->testConnection();
    }

    public function testTheStatusIsTheHostsVitalsAndItsXrayCore(): void
    {
        $provider = $this->provider([
            self::json(200, self::system()),
            self::json(200, self::system(['xray_running' => false, 'xray_version' => null, 'last_xray_error' => 'failed to start core: listen tcp :443: bind: address already in use'])),
        ]);

        $running = $provider->status();
        $stopped = $provider->status();

        self::assertSame(['GET /api/system', 'GET /api/system'], $this->panel->calls());
        self::assertSame([12.5, 2 * self::GIB, 8 * self::GIB, 20 * self::GIB, 80 * self::GIB], [$running->cpuPercent, $running->memoryUsedBytes, $running->memoryTotalBytes, $running->diskUsedBytes, $running->diskTotalBytes]);
        self::assertSame([CoreState::Running, 'Xray', '25.10.31', '', 86400], [$running->coreState, $running->coreName, $running->coreVersion, $running->coreError, $running->uptimeSeconds]);
        self::assertSame([CoreState::Stopped, '', 'failed to start core: listen tcp :443: bind: address already in use'], [$stopped->coreState, $stopped->coreVersion, $stopped->coreError]);
    }

    public function testTheInboundsAreItsServicesKeyedByIdWithTheirUsers(): void
    {
        $provider = $this->provider([self::json(200, ['services' => [
            self::panelService(['id' => 3, 'name' => 'Germany', 'host_count' => 4, 'user_count' => 120]),
            self::panelService(['id' => 5, 'name' => 'No hosts', 'host_count' => 0, 'user_count' => 0, 'has_hosts' => false, 'broken' => true]),
            self::panelService(['id' => 8, 'name' => 'Netherlands', 'host_count' => 2, 'user_count' => 7]),
        ], 'total' => 3])]);

        $inbounds = $provider->listInbounds();

        self::assertSame(['GET /api/v2/services'], $this->panel->calls());
        self::assertSame('limit=null', $this->panel->request(0)->getUri()->getQuery(), 'every service at once (services.go servicePagination), not its first 20');
        self::assertSame(['3', '5', '8'], array_map(static fn($inbound): string => $inbound->key, $inbounds));
        [$de, $none, $nl] = $inbounds;
        self::assertSame(['Germany', 'Germany', true, 120], [$de->tag, $de->remark, $de->enabled, $de->clientCount]);
        self::assertFalse($none->enabled, 'a service with no active host takes no user');
        self::assertSame(['Netherlands', 7], [$nl->remark, $nl->clientCount]);
        self::assertSame([null, null, null, null], [$de->protocol, $de->port, $de->network, $de->security], 'a service is no single inbound: nothing made up');
    }

    public function testThePanelAlwaysServesSubscriptionsWithoutBeingAsked(): void
    {
        self::assertTrue($this->provider([])->servesSubscriptions());
        self::assertSame([], $this->panel->calls());
    }

    /** POST /api/user with service_id and the term Rebecca's validation takes (validation.go validateOnHold: a duration, no expire). */
    public function testANewClientIsMadeInItsOneServiceOnHold(): void
    {
        $provider = $this->provider([self::json(201, self::user())]);

        $client = $provider->createClient(['3', '3'], new ClientSpec(
            name: 'amir_1',
            totalBytes: 30 * self::GIB,
            expiry: Expiry::afterFirstUse(self::MONTH),
            ipLimit: 2,
            telegramId: 42,
            comment: '42 | amir',
        ));

        self::assertSame(['POST /api/user'], $this->panel->calls(), 'Rebecca answers the user itself');
        self::assertBody([
            'username' => 'amir_1',
            'service_id' => 3,
            'data_limit' => 30 * self::GIB,
            'data_limit_reset_strategy' => 'no_reset',
            'ip_limit' => 2,
            'status' => 'on_hold',
            'on_hold_expire_duration' => self::MONTH,
            'note' => '42 | amir',
        ], $this->panel->params(0), 'no expire beside a duration, no credentials (the panel mints its key), no inbounds');

        self::assertSame(['amir_1', true, 30 * self::GIB, 0, 0, 2], [$client->name, $client->enabled, $client->totalBytes, $client->uploadBytes, $client->downloadBytes, $client->ipLimit]);
        self::assertSame(self::MONTH, $client->expiry->pendingSeconds());
        self::assertSame(self::BASE . '/sub/' . self::CREDENTIAL, $client->subscriptionUrl, 'the panel\'s path made absolute');
        self::assertNull($client->online);
    }

    public function testADeadlineIsUnixSecondsAndNoTermIsUnlimitedValidity(): void
    {
        $deadline = new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC'));
        $provider = $this->provider([
            self::json(201, self::user(['status' => 'active', 'on_hold_expire_duration' => null, 'expire' => $deadline->getTimestamp()])),
            self::json(201, self::user(['username' => 'amir_2', 'status' => 'active', 'on_hold_expire_duration' => null, 'data_limit' => null])),
            self::json(201, self::user(['username' => 'amir_3', 'status' => 'active', 'on_hold_expire_duration' => null])),
        ]);

        $fixed = $provider->createClient(['3'], new ClientSpec('amir_1', 10 * self::GIB, Expiry::at($deadline)));
        $never = $provider->createClient(['3'], new ClientSpec('amir_2'));
        $provider->createClient(['3'], new ClientSpec('amir_3', telegramId: 42));

        self::assertBody(['username' => 'amir_1', 'service_id' => 3, 'data_limit' => 10 * self::GIB, 'data_limit_reset_strategy' => 'no_reset', 'ip_limit' => 0, 'status' => 'active', 'expire' => $deadline->getTimestamp()], $this->panel->params(0), 'nothing to say leaves the note alone');
        self::assertEquals($deadline, $fixed->expiry->deadline());
        self::assertBody(['username' => 'amir_2', 'service_id' => 3, 'data_limit' => 0, 'data_limit_reset_strategy' => 'no_reset', 'ip_limit' => 0, 'status' => 'active', 'expire' => 0], $this->panel->params(1), 'as Rebecca reads its absence anyway (permissions.go createConstraintInput)');
        self::assertSame([null, null, 0], [$never->expiry->deadline(), $never->expiry->pendingSeconds(), $never->totalBytes]);
        self::assertSame('42', $this->panel->params(2)['note'], 'the Telegram id when no comment is given');
    }

    public function testSeveralServicesForOneClientAreRefusedBeforeAnythingIsSent(): void
    {
        $provider = $this->provider([]);

        try {
            $provider->createClient(['3', '8'], new ClientSpec('amir_1'));
            self::fail('a Rebecca user is in one service');
        } catch (RebeccaApiException $e) {
            self::assertSame([], $this->panel->calls());
            self::assertSame(400, $e->httpStatus);
            self::assertStringContainsString('one service', $e->panelMessage);
            self::assertStringContainsString('فقط یک سرویس', $e->hint);
            // A whole-server plan, a free test and a built service take every service marked for sale: no plan to fix there.
            self::assertStringContainsString('کل سرور', $e->hint);
            self::assertStringContainsString('اکانت تست', $e->hint);
            self::assertStringContainsString('برای فروش علامت بزنید', $e->hint);
            self::assertFalse($e->mayHaveBeenDone());
        }

        $this->expectException(\InvalidArgumentException::class);
        $provider->createClient([], new ClientSpec('amir_1'));
    }

    /** Rebecca makes a user active or on hold only (validation.go): one wanted off is made, then switched off, its term kept. */
    public function testAClientWantedSwitchedOffIsMadeThenSwitchedOff(): void
    {
        $provider = $this->provider([self::json(201, self::user()), self::json(200, self::user(['status' => 'disabled']))]);

        $client = $provider->createClient(['3'], new ClientSpec('amir_1', 10 * self::GIB, Expiry::afterFirstUse(self::MONTH), enabled: false));

        self::assertSame(['POST /api/user', 'PUT /api/user/amir_1'], $this->panel->calls());
        self::assertSame('on_hold', $this->panel->params(0)['status']);
        self::assertSame(['status' => 'disabled'], $this->panel->params(1), 'only its switch: its waiting term stays on it');
        self::assertFalse($client->enabled);
        self::assertSame(self::MONTH, $client->expiry->pendingSeconds(), 'off before its clock started: still waiting');
    }

    public function testAClientThePanelWillNotSwitchOffIsTakenBackOrElseFailsAsMade(): void
    {
        $refusal = static fn(): Response => self::json(403, ['detail' => 'لیمیت حجم شما به پایان رسید']);
        $provider = $this->provider([self::json(201, self::user()), $refusal(), self::json(200, ['username' => 'amir_1', 'status' => 'deleted'])]);
        try {
            $provider->createClient(['3'], new ClientSpec('amir_1', enabled: false));
            self::fail('the switch was refused');
        } catch (RebeccaApiException $e) {
            self::assertSame(['POST /api/user', 'PUT /api/user/amir_1', 'DELETE /api/user/amir_1'], $this->panel->calls());
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

    /** A write Rebecca took without the detail after it ({id, username, status, detail}): the user is read; a failed read is a failure after the write. */
    public function testAWriteAnsweredWithoutItsDetailReadsTheUserAgain(): void
    {
        $taken = static fn(string $status): Response => self::json(201, ['id' => 7, 'username' => 'amir_1', 'status' => $status, 'detail' => self::NO_DETAIL]);
        $provider = $this->provider([$taken('on_hold'), self::json(200, self::user())]);

        $client = $provider->createClient(['3'], new ClientSpec('amir_1', expiry: Expiry::afterFirstUse(self::MONTH)));

        self::assertSame(['POST /api/user', 'GET /api/user/amir_1'], $this->panel->calls());
        self::assertSame([self::MONTH, self::BASE . '/sub/' . self::CREDENTIAL], [$client->expiry->pendingSeconds(), $client->subscriptionUrl]);

        $provider = $this->provider([$taken('on_hold'), self::json(502, ['detail' => 'context deadline exceeded'])]);
        try {
            $provider->createClient(['3'], new ClientSpec('amir_1'));
            self::fail('the read failed');
        } catch (ProviderException $e) {
            self::assertTrue($e->mayHaveBeenDone(), 'made: never made again under the next name');
        }

        // The same for a change and a revocation.
        $provider = $this->provider([self::json(200, self::user()), $taken('on_hold'), self::json(200, self::user(['data_limit' => 50 * self::GIB]))]);
        self::assertSame(50 * self::GIB, $provider->updateClient(new ClientSpec('amir_1', 50 * self::GIB, Expiry::afterFirstUse(self::MONTH)))->totalBytes);
        self::assertSame(['GET /api/user/amir_1', 'PUT /api/user/amir_1', 'GET /api/user/amir_1'], $this->panel->calls());

        $provider = $this->provider([$taken('active'), self::json(404, ['detail' => 'User not found'])]);
        try {
            $provider->rotateClientCredentials('amir_1');
            self::fail('the read failed');
        } catch (NotFoundException $e) {
            self::assertTrue($e->mayHaveBeenDone());
        }
    }

    /** One PUT of the fields the shop sets: Rebecca keeps every field left out (mutation_repository.go updateUserMutation) — its service, next plans, key. */
    public function testAnUpdateReadsTheUserThenPutsItsLimitsTermSwitchAndNote(): void
    {
        $provider = $this->provider([self::json(200, self::user()), self::json(200, self::user(['data_limit' => 50 * self::GIB, 'ip_limit' => 3, 'on_hold_expire_duration' => 2 * self::MONTH]))]);

        $client = $provider->updateClient(new ClientSpec('amir_1', 50 * self::GIB, Expiry::afterFirstUse(2 * self::MONTH), ipLimit: 3, telegramId: 42));

        self::assertSame(['GET /api/user/amir_1', 'PUT /api/user/amir_1'], $this->panel->calls());
        self::assertBody([
            'data_limit' => 50 * self::GIB,
            'data_limit_reset_strategy' => 'no_reset',
            'ip_limit' => 3,
            'status' => 'on_hold',
            'on_hold_expire_duration' => 2 * self::MONTH,
            'note' => '42',
        ], $this->panel->params(1), 'no expire for a waiting term on a user without a deadline: `expire: 0` asks for unlimited validity (permissions.go EnsureUserConstraints)');
        self::assertSame([50 * self::GIB, 3, 2 * self::MONTH], [$client->totalBytes, $client->ipLimit, $client->expiry->pendingSeconds()]);
    }

    /**
     * term(): the deadline sent to set one or `expire: 0` to take off the one the user had — never 0 otherwise —; a waiting
     * term on hold with its duration; a replaced one's duration cleared with 0; the switch always.
     */
    public function testAnUpdatesTermIsWhatTheUserHadAndWhatItGets(): void
    {
        $old = (new \DateTimeImmutable('2026-12-01 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $new = new \DateTimeImmutable('2027-01-01 00:00:00', new \DateTimeZone('UTC'));
        $waiting = ['status' => 'on_hold', 'expire' => null, 'on_hold_expire_duration' => self::MONTH];
        $dated = ['status' => 'active', 'expire' => $old, 'on_hold_expire_duration' => null];
        $endless = ['status' => 'active', 'expire' => null, 'on_hold_expire_duration' => null];
        $cases = [
            'waiting, given a deadline' => [$waiting, Expiry::at($new), true, ['status' => 'active', 'on_hold_expire_duration' => 0, 'expire' => $new->getTimestamp()]],
            'dated, given a waiting term' => [$dated, Expiry::afterFirstUse(2 * self::MONTH), true, ['status' => 'on_hold', 'on_hold_expire_duration' => 2 * self::MONTH, 'expire' => 0]],
            'dated, given another deadline' => [$dated, Expiry::at($new), true, ['status' => 'active', 'on_hold_expire_duration' => 0, 'expire' => $new->getTimestamp()]],
            'dated, given no end' => [$dated, Expiry::never(), true, ['status' => 'active', 'on_hold_expire_duration' => 0, 'expire' => 0]],
            'endless, given no end' => [$endless, Expiry::never(), true, ['status' => 'active', 'on_hold_expire_duration' => 0]],
            'waiting, kept waiting off' => [$waiting, Expiry::afterFirstUse(self::MONTH), false, ['status' => 'disabled', 'on_hold_expire_duration' => self::MONTH]],
            'dated, given a deadline off' => [$dated, Expiry::at($new), false, ['status' => 'disabled', 'on_hold_expire_duration' => 0, 'expire' => $new->getTimestamp()]],
            'a 0 deadline is none' => [['status' => 'active', 'expire' => 0, 'on_hold_expire_duration' => null], Expiry::afterFirstUse(self::MONTH), true, ['status' => 'on_hold', 'on_hold_expire_duration' => self::MONTH]],
        ];

        foreach ($cases as $case => [$had, $expiry, $enabled, $term]) {
            $provider = $this->provider([self::json(200, self::user($had)), self::json(200, self::user())]);

            $provider->updateClient(new ClientSpec('amir_1', 20 * self::GIB, $expiry, ipLimit: 2, enabled: $enabled));

            self::assertBody(['data_limit' => 20 * self::GIB, 'data_limit_reset_strategy' => 'no_reset', 'ip_limit' => 2] + $term, $this->panel->params(1), $case);
            self::assertArrayNotHasKey('next_plan', $this->panel->params(1), "{$case}: Rebecca refuses next_plan (service.go)");
            self::assertArrayNotHasKey('service_id', $this->panel->params(1), "{$case}: its service stays");
        }
    }

    public function testAnUpdateOfAUserThePanelDoesNotHaveIsNotFound(): void
    {
        $provider = $this->provider([self::json(404, ['detail' => 'User not found'])]);

        try {
            $provider->updateClient(new ClientSpec('amir_1'));
            self::fail('no such user');
        } catch (NotFoundException $e) {
            self::assertSame(['GET /api/user/amir_1'], $this->panel->calls(), 'nothing put');
            self::assertFalse($e->mayHaveBeenDone());
        }
    }

    /** revoke_sub (mutation_repository.go revokeUserMutation): a new credential key — new configs and links, the old ones stop. */
    public function testRotatingRevokesTheSubscriptionForANewKeyAndLink(): void
    {
        $key = 'c2d1f4a5123440bc8def0123456789cd';
        $provider = $this->provider([self::json(200, self::user(['credential_key' => $key, 'subscription_url' => '/sub/' . $key]))]);

        $client = $provider->rotateClientCredentials('amir_1');

        self::assertSame(['POST /api/user/amir_1/revoke_sub'], $this->panel->calls());
        self::assertSame('', (string) $this->panel->request(0)->getBody());
        self::assertSame(self::BASE . '/sub/' . $key, $client->subscriptionUrl);
    }

    public function testSwitchingIsANoOpWhenTheUserIsAsAskedAlready(): void
    {
        foreach (['off, asked off' => ['disabled', false], 'on, asked on' => ['active', true], 'on hold, asked on' => ['on_hold', true], 'limited, asked on' => ['limited', true], 'expired, asked on' => ['expired', true]] as $case => [$status, $enabled]) {
            $provider = $this->provider([self::json(200, self::user(['status' => $status]))]);

            $provider->setClientEnabled('amir_1', $enabled);

            self::assertSame(['GET /api/user/amir_1'], $this->panel->calls(), $case);
        }
    }

    public function testSwitchedOffIsDisabledAlone(): void
    {
        foreach (['active', 'on_hold', 'limited'] as $status) {
            $provider = $this->provider([self::json(200, self::user(['status' => $status, 'expire' => 1893456000])), self::json(200, self::user(['status' => 'disabled']))]);

            $provider->setClientEnabled('amir_1', false);

            self::assertSame(['GET /api/user/amir_1', 'PUT /api/user/amir_1'], $this->panel->calls(), $status);
            self::assertSame(['status' => 'disabled'], $this->panel->params(1), "{$status}: its term and limits as they are");
        }
    }

    /** Off before its clock started, a user goes back on hold with its duration and no expire (validation.go validateOnHold). */
    public function testSwitchedOnAWaitingTermGoesBackOnHold(): void
    {
        $provider = $this->provider([self::json(200, self::user(['status' => 'disabled'])), self::json(200, self::user())]);

        $provider->setClientEnabled('amir_1', true);

        self::assertSame(['GET /api/user/amir_1', 'PUT /api/user/amir_1'], $this->panel->calls());
        self::assertSame(['status' => 'on_hold', 'on_hold_expire_duration' => self::MONTH], $this->panel->params(1), 'plain active would leave it with no end at all');
    }

    /**
     * Any other goes active with its own limit and deadline sent back, so Rebecca finds it limited or expired at once
     * (mutation_repository.go updateUserMutation recomputes the status when data_limit or expire is sent) — never a 0,
     * which its permission checks read as unlimited (permissions.go EnsureUserConstraints, EnsureDataLimitPermission).
     */
    public function testSwitchedOnAnyOtherGoesActiveWithItsOwnLimitAndDeadline(): void
    {
        $provider = $this->provider([self::json(200, self::user(['status' => 'disabled', 'on_hold_expire_duration' => null, 'expire' => 1893456000, 'data_limit' => 30 * self::GIB, 'used_traffic' => 31 * self::GIB])), self::json(200, self::user(['status' => 'limited']))]);
        $provider->setClientEnabled('amir_1', true);
        self::assertSame(['status' => 'active', 'data_limit' => 30 * self::GIB, 'expire' => 1893456000], $this->panel->params(1));

        $provider = $this->provider([self::json(200, self::user(['status' => 'disabled', 'on_hold_expire_duration' => null, 'expire' => null, 'data_limit' => null])), self::json(200, self::user(['status' => 'active']))]);
        $provider->setClientEnabled('amir_1', true);
        self::assertSame(['status' => 'active'], $this->panel->params(1), 'unlimited both ways: nothing to send back');

        // A duration left on a user with a deadline is no waiting term (Rebecca clears it as the term starts).
        $provider = $this->provider([self::json(200, self::user(['status' => 'disabled', 'on_hold_expire_duration' => self::MONTH, 'expire' => 1893456000, 'data_limit' => 0])), self::json(200, self::user(['status' => 'active']))]);
        $provider->setClientEnabled('amir_1', true);
        self::assertSame(['status' => 'active', 'expire' => 1893456000], $this->panel->params(1));
    }

    /** DELETE is Rebecca's soft delete (status «deleted»): gone for every read, its name free again. */
    public function testDeletingIsOneCallAndAMissingUserIsNotFound(): void
    {
        $provider = $this->provider([self::json(200, ['username' => 'amir_1', 'status' => 'deleted']), self::json(404, ['detail' => 'User not found'])]);

        $provider->deleteClient('amir_1');
        self::assertSame(['DELETE /api/user/amir_1'], $this->panel->calls());

        $this->expectException(NotFoundException::class);
        $provider->deleteClient('amir_1');
    }

    /** reset (mutation_repository.go resetUserMutation): usage 0, a limited user active, any other status kept — nothing to put back. */
    public function testResettingTheTrafficIsOneCall(): void
    {
        $provider = $this->provider([self::json(200, self::user(['status' => 'on_hold', 'used_traffic' => 0]))]);

        $provider->resetClientTraffic('amir_1');

        self::assertSame(['POST /api/user/amir_1/reset'], $this->panel->calls());
        self::assertSame('', (string) $this->panel->request(0)->getBody());
    }

    public function testFindClientReadsTheUserAndIsNullForOneThePanelDoesNotHave(): void
    {
        $provider = $this->provider([self::json(200, self::user()), self::json(404, ['detail' => 'User not found'])]);

        $client = $provider->findClient('amir_1');
        self::assertSame(['amir_1', 2, null], [$client?->name, $client?->ipLimit, $client?->online], 'not asked: unknown');
        self::assertSame(['GET /api/user/amir_1'], $this->panel->calls());

        self::assertNull($provider->findClient('amir_1'));

        // Another admin's user is no missing one: the refusal stands.
        $provider = $this->provider([self::json(403, ['detail' => "You're not allowed"])]);
        $this->expectException(RebeccaApiException::class);
        $provider->findClient('someone_else');
    }

    /** Presence is the panel's own window (users.go handleOnlineUsers), asked about the name it gave, whatever its case. */
    public function testFindClientAsksThePanelWhoIsOnlineWhenAsked(): void
    {
        $provider = $this->provider([
            self::json(200, self::user(['username' => 'Amir_1'])),
            self::json(200, ['AMIR_1']),
            self::json(200, self::user()),
            self::json(200, []),
            self::json(200, self::user()),
            self::json(200, ['amir_10', 'sara_2']),
        ]);

        self::assertTrue($provider->findClient('amir_1', presence: true)?->online);
        self::assertSame(['GET /api/user/amir_1', 'GET /api/users/onlines'], $this->panel->calls());
        self::assertSame('usernames=Amir_1', $this->panel->request(1)->getUri()->getQuery(), 'the name as the panel has it');

        self::assertFalse($provider->findClient('Amir_1', presence: true)?->online, 'nobody online');
        self::assertFalse($provider->findClient('AMIR_1', presence: true)?->online, 'others online');
        self::assertSame(['usernames=amir_1', 'usernames=amir_1'], [$this->panel->request(3)->getUri()->getQuery(), $this->panel->request(5)->getUri()->getQuery()], 'asked as the panel names it, whatever the case asked for');
    }

    public function testAnAdminWhoSeesOnlyItsOwnUsersHasNoListing(): void
    {
        foreach (['standard', 'reseller'] as $role) {
            $provider = $this->provider([self::json(200, self::admin($role))]);
            try {
                $provider->listClients();
                self::fail("a {$role} admin lists only its own users");
            } catch (UnsupportedOperationException) {
                self::assertSame(['GET /api/admin'], $this->panel->calls(), $role);
            }
        }
    }

    public function testAGlobalAdminsUsersArePagedByName500AtATime(): void
    {
        $page = static fn(int $from, int $count, int $total): Response => self::json(200, self::page(array_map(static fn(int $i): array => self::listed(['username' => sprintf('user_%04d', $i)]), range($from, $from + $count - 1)), $total));
        $provider = $this->provider([self::json(200, self::admin('sudo')), $page(1, 500, 1002), $page(501, 500, 1002), $page(1001, 2, 1002), $page(1, 2, 2)]);

        $clients = $provider->listClients();

        self::assertSame(['GET /api/admin', 'GET /api/users', 'GET /api/users', 'GET /api/users'], $this->panel->calls());
        self::assertSame(['offset=0&limit=500&sort=username', 'offset=500&limit=500&sort=username', 'offset=1000&limit=500&sort=username'], array_map(fn(int $i): string => $this->panel->request($i)->getUri()->getQuery(), [1, 2, 3]));
        self::assertCount(1002, $clients);
        self::assertSame(['user_0001', null, self::BASE . '/sub/' . self::CREDENTIAL], [$clients[0]->name, $clients[0]->ipLimit, $clients[0]->subscriptionUrl], 'the list leaves out the device limit');

        // Its role is asked once for the client's life.
        $this->panel->reset();
        $provider->listClients();
        self::assertSame(['GET /api/users'], $this->panel->calls());
    }

    public function testTheTotalOrAShortPageEndsTheListing(): void
    {
        $full = self::page(array_map(static fn(int $i): array => self::listed(['username' => "user_{$i}"]), range(1, 500)), 500);
        $provider = $this->provider([self::json(200, self::admin('full_access')), self::json(200, $full)]);
        self::assertCount(500, $provider->listClients());
        self::assertCount(2, $this->panel->calls(), 'a full page that is the whole total asks for no other');

        $provider = $this->provider([self::json(200, self::admin('full_access')), self::json(200, ['users' => [self::listed()]])]);
        self::assertCount(1, $provider->listClients());
        self::assertCount(2, $this->panel->calls(), 'no total: a short page is the last');
    }

    public function testAPanelThatNeverStopsAnsweringFullPagesIsNotListedAtOnce(): void
    {
        $full = self::json(200, ['users' => array_map(static fn(int $i): array => ['username' => "user_{$i}", 'status' => 'active', 'expire' => null], range(1, 500))]);
        $provider = $this->provider([self::json(200, self::admin('sudo')), ...array_fill(0, 100, $full)]);

        try {
            $provider->listClients();
            self::fail('a hundred pages and no end');
        } catch (UnsupportedOperationException) {
            self::assertCount(101, $this->panel->calls());
        }
    }

    /**
     * The list leaves out a user's waiting term and device limit (dto.go UserListItem): one on hold, or off without a
     * deadline, is read on its own; the rest come from the list, their device limit unsaid (ipLimit null).
     */
    public function testAUserWhoseTermMayWaitIsReadOnItsOwn(): void
    {
        $deadline = 1893456000;
        $provider = $this->provider([
            self::json(200, self::admin('full_access')),
            self::json(200, self::page([
                self::listed(['username' => 'ali_3', 'status' => 'disabled', 'expire' => null]),
                self::listed(['username' => 'amir_1', 'status' => 'active', 'expire' => $deadline, 'used_traffic' => 5 * self::GIB]),
                self::listed(['username' => 'gone_6', 'status' => 'on_hold', 'expire' => null]),
                self::listed(['username' => 'mina_5', 'status' => 'expired', 'expire' => 1700000000]),
                self::listed(['username' => 'reza_4', 'status' => 'disabled', 'expire' => $deadline]),
                self::listed(['username' => 'sara_2', 'status' => 'on_hold', 'expire' => null]),
            ], 6)),
            self::json(200, self::user(['username' => 'ali_3', 'status' => 'disabled', 'ip_limit' => 1])),
            self::json(404, ['detail' => 'User not found']),
            self::json(200, self::user(['username' => 'sara_2', 'ip_limit' => 0, 'on_hold_expire_duration' => 2 * self::MONTH])),
        ]);

        $clients = array_column(array_map(static fn($client): array => ['client' => $client, 'name' => $client->name], $provider->listClients()), 'client', 'name');

        self::assertSame(['GET /api/admin', 'GET /api/users', 'GET /api/user/ali_3', 'GET /api/user/gone_6', 'GET /api/user/sara_2'], $this->panel->calls());
        self::assertSame(['ali_3', 'amir_1', 'mina_5', 'reza_4', 'sara_2'], array_keys($clients), 'one gone meanwhile is left out');
        self::assertSame([false, self::MONTH, 1], [$clients['ali_3']->enabled, $clients['ali_3']->expiry->pendingSeconds(), $clients['ali_3']->ipLimit], 'off before its clock started');
        self::assertSame([2 * self::MONTH, 0], [$clients['sara_2']->expiry->pendingSeconds(), $clients['sara_2']->ipLimit]);
        self::assertSame([$deadline, 5 * self::GIB, null], [$clients['amir_1']->expiry->deadline()?->getTimestamp(), $clients['amir_1']->downloadBytes, $clients['amir_1']->ipLimit]);
        self::assertSame([false, $deadline, null], [$clients['reza_4']->enabled, $clients['reza_4']->expiry->deadline()?->getTimestamp(), $clients['reza_4']->ipLimit], 'a deadline: nothing waits');
        self::assertTrue($clients['mina_5']->enabled, 'expired is the panel\'s own word, not the shop\'s switch');
    }

    /** Rebecca matches a username whatever its case: one seen twice (a page boundary moved) is one client. */
    public function testAUserListedTwiceInAnotherCaseIsOneClient(): void
    {
        $provider = $this->provider([self::json(200, self::admin('sudo')), self::json(200, self::page([self::listed(['username' => 'amir_1']), self::listed(['username' => 'Amir_1', 'used_traffic' => 7])], 2))]);

        $clients = $provider->listClients();

        self::assertCount(1, $clients);
        self::assertSame(7, $clients[0]->downloadBytes, 'the last reading');
    }

    /** A token link: the path read off a user's own token link, then `<path>/<token>/info` (subscriptions.go), then the user. */
    public function testASubscriptionLinksTokenIsShownToThePanelsSubscriptionRoute(): void
    {
        $provider = $this->provider([self::json(200, self::page([self::listed()], 1)), self::json(200, self::info('amir_1')), self::json(200, self::user())]);

        $client = $provider->findClientByLink(self::link('سرویسم: https://sub.example.com/sub/' . self::TOKEN));

        self::assertSame('amir_1', $client?->name);
        self::assertSame(['GET /api/users', 'GET /sub/' . self::TOKEN . '/info', 'GET /api/user/amir_1'], $this->panel->calls());
        self::assertSame('offset=0&limit=1', $this->panel->request(0)->getUri()->getQuery());
        self::assertSame('rb.example', $this->panel->request(1)->getUri()->getHost(), 'asked of the panel, not of the host in the link');
        self::assertFalse($this->panel->request(1)->hasHeader('Authorization'), 'a public route: no key');
        self::assertSame('Bearer ' . self::KEY, $this->panel->request(2)->getHeaderLine('Authorization'));
    }

    /** A credential-key link — alone or after the username — and a VLESS or VMess config's id: the key, dashes dropped. */
    public function testACredentialKeyLinkAndAVlessOrVmessIdAreTheUsersKey(): void
    {
        $vmess = 'vmess://' . base64_encode((string) json_encode(['v' => '2', 'ps' => 'amir_1', 'add' => 'rb.example', 'port' => '443', 'id' => self::UUID, 'net' => 'ws']));
        foreach ([
            'a key link' => 'https://rb.example:8000/sub/' . self::CREDENTIAL,
            'a username/key link' => 'https://rb.example:8000/sub/amir_1/' . self::CREDENTIAL,
            'a key#username link' => 'https://rb.example:8000/sub/' . self::CREDENTIAL . '#amir_1',
            'a VLESS config' => 'vless://' . strtoupper(self::UUID) . '@de1.example.com:443?security=reality&type=tcp#amir_1',
            'a VMess config' => $vmess,
        ] as $case => $text) {
            $provider = $this->provider([self::json(200, self::page([self::listed()], 1)), self::json(200, self::info('amir_1')), self::json(200, self::user())]);

            self::assertSame('amir_1', $provider->findClientByLink(self::link($text))?->name, $case);
            self::assertSame(['GET /api/users', 'GET /sub/' . self::CREDENTIAL . '/info', 'GET /api/user/amir_1'], $this->panel->calls(), "{$case}: a username is too short to be asked");
        }
    }

    /** A Trojan or Shadowsocks password is a hash of the key (config_links.go keyToPassword): nothing on the panel looks it up. */
    public function testAPasswordConfigMatchesNoOne(): void
    {
        $hashed = substr(hash('sha256', 'trojan:' . self::CREDENTIAL), 0, 32);
        $provider = $this->provider([self::json(200, self::page([self::listed()], 1)), self::json(404, ['detail' => 'Not Found'])]);

        self::assertNull($provider->findClientByLink(self::link("trojan://{$hashed}@rb.example:443?security=tls#amir_1")));
        self::assertSame(['GET /api/users', "GET /sub/{$hashed}/info"], $this->panel->calls(), 'shaped as a key, it is asked — and is none');

        $provider = $this->provider([]);
        self::assertNull($provider->findClientByLink(self::link('trojan://Tr0jan-Pass-1@rb.example:443 ss://' . base64_encode('chacha20-ietf-poly1305:Sh4d0w-Pass-1') . '@rb.example:8388')));
        self::assertSame([], $this->panel->calls(), 'nothing a panel mints for a link: not a call');
    }

    /** The panel's path may be several folders, behind a prefix of the owner's: the last three are tried with that user's own token, longest first. */
    public function testThePanelsSubscriptionPathIsTriedOffAUsersOwnTokenLink(): void
    {
        $own = 'Zmlyc3RfMCwxNzYwMjYyNDAwAbCdEfGhIj';
        $provider = $this->provider([
            self::json(200, self::page([self::listed(['username' => 'first_0', 'subscription_urls' => ['key' => 'https://cdn.example/x/panel/my/sub/' . self::CREDENTIAL, 'token' => 'https://cdn.example/x/panel/my/sub/' . $own]])], 1)),
            self::json(404, ['detail' => 'Not Found']),
            self::json(200, self::info('first_0')),
            self::json(200, self::info('amir_1')),
            self::json(200, self::user()),
        ]);

        self::assertSame('amir_1', $provider->findClientByLink(self::link('https://cdn.example/x/panel/my/sub/' . self::TOKEN))?->name);
        self::assertSame([
            'GET /api/users',
            "GET /panel/my/sub/{$own}/info",
            "GET /my/sub/{$own}/info",
            'GET /my/sub/' . self::TOKEN . '/info',
            'GET /api/user/amir_1',
        ], $this->panel->calls(), 'four folders: the last three; the one answering with that user is the path');
        foreach ([1, 2, 3] as $i) {
            self::assertFalse($this->panel->request($i)->hasHeader('Authorization'));
        }

        // None answering with that user: no path, and the link's own token is not asked.
        $provider = $this->provider([
            self::json(200, self::page([self::listed(['username' => 'first_0', 'subscription_urls' => ['token' => 'https://cdn.example/my/sub/' . $own]])], 1)),
            self::json(200, self::info('someone_else')),
            self::json(404, ['detail' => 'Not Found']),
        ]);
        self::assertNull($provider->findClientByLink(self::link('https://cdn.example/my/sub/' . self::TOKEN)));
        self::assertSame(['GET /api/users', "GET /my/sub/{$own}/info", "GET /sub/{$own}/info"], $this->panel->calls());

        // No user to read it off: nothing more to ask.
        $provider = $this->provider([self::json(200, self::page([], 0))]);
        self::assertNull($provider->findClientByLink(self::link('https://rb.example:8000/sub/' . self::TOKEN)));
        self::assertSame(['GET /api/users'], $this->panel->calls());
    }

    /** One folder is the path at once — and under a reverse proxy's path the route is asked under it too. */
    public function testOneFolderIsThePathAtOnceUnderThePanelsOwnAddress(): void
    {
        $provider = $this->provider([self::json(200, self::page([self::listed(['subscription_urls' => ['token' => '/custom/' . self::TOKEN]])], 1)), self::json(200, self::info('amir_1')), self::json(200, self::user())], base: 'https://example.com/rb');

        self::assertSame('amir_1', $provider->findClientByLink(self::link('https://example.com/custom/' . self::TOKEN))?->name);
        self::assertSame(['GET /rb/api/users', 'GET /rb/custom/' . self::TOKEN . '/info', 'GET /rb/api/user/amir_1'], $this->panel->calls());
    }

    public function testALinkNoUserHasIsNoneAndOnlyAFewOfItsPartsAreAsked(): void
    {
        $tokens = ['QWxpXzEsMTc2MDI2MjQwMAaaaaaaaaa', 'QWxpXzIsMTc2MDI2MjQwMAbbbbbbbbbb', 'QWxpXzMsMTc2MDI2MjQwMAcccccccccc', 'QWxpXzQsMTc2MDI2MjQwMAdddddddddd', 'QWxpXzUsMTc2MDI2MjQwMAeeeeeeeeee'];
        $gone = static fn(): Response => self::json(404, ['detail' => 'Not Found']);
        $provider = $this->provider([self::json(200, self::page([self::listed()], 1)), $gone(), $gone(), $gone(), $gone(), $gone()]);

        self::assertNull($provider->findClientByLink(self::link(implode(' ', array_map(static fn(string $token): string => "https://rb.example:8000/sub/{$token}", $tokens)))));
        self::assertCount(5, $this->panel->calls(), 'the path, then at most four: each is a request');

        // An answer in another shape (Marzban's /info has the username at its root) names no user here.
        $provider = $this->provider([self::json(200, self::page([self::listed()], 1)), self::json(200, ['username' => 'amir_1', 'status' => 'active'])]);
        self::assertNull($provider->findClientByLink(self::link('https://rb.example:8000/sub/' . self::TOKEN)));
        self::assertCount(2, $this->panel->calls());
    }

    public function testAUserIsTheContractsClient(): void
    {
        $provider = $this->provider([
            self::json(200, self::user(['status' => 'limited', 'on_hold_expire_duration' => null, 'expire' => 1893456000, 'used_traffic' => 31 * self::GIB, 'lifetime_used_traffic' => 90 * self::GIB, 'online_at' => '2026-11-14T09:30:00.123456Z', 'ip_limit' => 0])),
            self::json(200, self::user(['status' => 'active', 'on_hold_expire_duration' => null, 'data_limit' => null, 'online_at' => '2026-11-14 09:30:00', 'ip_limit' => -1])),
            self::json(200, self::user(['status' => 'on_hold', 'expire' => 1893456000, 'online_at' => '2026-11-14T13:00:00+03:30'])),
            self::json(200, self::user(['status' => 'active', 'on_hold_expire_duration' => self::MONTH, 'online_at' => 'never'])),
        ]);

        $limited = $provider->findClient('amir_1');
        $unlimited = $provider->findClient('amir_1');
        $dated = $provider->findClient('amir_1');
        $started = $provider->findClient('amir_1');

        self::assertNotNull($limited);
        self::assertSame([true, 0, 31 * self::GIB, 30 * self::GIB, 0], [$limited->enabled, $limited->uploadBytes, $limited->downloadBytes, $limited->totalBytes, $limited->ipLimit], 'one total, as download; the term\'s usage, not its lifetime');
        self::assertSame(1893456000, $limited->expiry->deadline()?->getTimestamp());
        self::assertSame('2026-11-14 09:30:00', $limited->lastOnlineAt?->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $limited->lastOnlineAt->getTimezone()->getName());

        self::assertNotNull($unlimited);
        self::assertSame([0, 0, null, null], [$unlimited->totalBytes, $unlimited->ipLimit, $unlimited->expiry->deadline(), $unlimited->expiry->pendingSeconds()]);
        self::assertSame('2026-11-14 09:30:00', $unlimited->lastOnlineAt?->format('Y-m-d H:i:s'), 'a moment without a zone is UTC');

        self::assertSame([1893456000, null], [$dated?->expiry->deadline()?->getTimestamp(), $dated?->expiry->pendingSeconds()], 'a deadline wins over a stray duration');
        self::assertSame('2026-11-14 09:30:00', $dated?->lastOnlineAt?->format('Y-m-d H:i:s'));

        self::assertSame([null, null], [$started?->expiry->pendingSeconds(), $started?->lastOnlineAt], 'active with a duration left on it waits for nothing; an unreadable moment is none');
    }

    public function testTheLinkIsThePanelsMadeAbsoluteOrTheServersPrefixBeforeItsKey(): void
    {
        $provider = $this->provider([
            self::json(200, self::user(['subscription_url' => 'https://sub.example.com:2096/sub/' . self::CREDENTIAL])),
            self::json(200, self::user(['subscription_url' => ''])),
        ]);
        self::assertSame('https://sub.example.com:2096/sub/' . self::CREDENTIAL, $provider->findClient('amir_1')?->subscriptionUrl, 'the panel\'s prefix: as it is');
        self::assertNull($provider->findClient('Amir_1')?->subscriptionUrl);

        $provider = $this->provider([
            self::json(200, self::user()),
            self::json(200, self::user(['subscription_url' => '/sub/amir_1/' . self::CREDENTIAL])),
            self::json(200, self::user(['subscription_url' => 'https://rb.example:8000/sub/' . self::CREDENTIAL . '#amir_1'])),
            self::json(200, self::user(['subscription_url' => '/sub/' . self::TOKEN])),
        ], ['subscription_url' => 'https://sub.example.com/s']);
        foreach (['a key link' => self::CREDENTIAL, 'a username/key link' => self::CREDENTIAL, 'a key#username link' => self::CREDENTIAL, 'a token link' => self::TOKEN] as $case => $identifier) {
            self::assertSame('https://sub.example.com/s/' . $identifier, $provider->findClient('amir_1')?->subscriptionUrl, $case);
        }
    }

    /**
     * FINDING (app/Modules/Providers/Drivers/Rebecca/RebeccaProvider.php note(), NOTE_MAX): the note is cut to 500
     * characters, but Rebecca measures 500 bytes — internal/app/user/validation.go validateUserBase: `if len(note) > 500`,
     * Go's len() of a string — and refuses a longer one with 400 «User's note can be a maximum of 500 character». A
     * Persian comment past 250 letters is cut to too many bytes, and the whole create (or update) is refused. Not weakened:
     * this test fails until the cut counts bytes.
     */
    public function testANoteIsCutToTheBytesRebeccaKeeps(): void
    {
        $provider = $this->provider([self::json(201, self::user())]);

        $provider->createClient(['3'], new ClientSpec('amir_1', comment: str_repeat('ب', 400)));

        $note = (string) $this->panel->params(0)['note'];
        self::assertNotSame('', $note);
        self::assertTrue(mb_check_encoding($note, 'UTF-8'), 'cut between letters, never inside one');
        self::assertLessThanOrEqual(500, strlen($note), 'validation.go: len(note) > 500 is refused');
    }

    /**
     * @param list<Response> $responses
     * @param array<string, mixed> $meta
     */
    private function provider(array $responses, array $meta = [], string $base = self::BASE): ProviderInterface
    {
        $this->app(); // registers the encrypter the Server casts need

        $this->panel = new FakePanel();
        $this->panel->raw(...$responses);

        $server = new Server(['name' => 'RB', 'driver' => 'rebecca', 'base_url' => $base, 'api_token' => self::KEY, 'meta' => $meta]);

        return (new RebeccaDriver())->connect($server, new PanelHttp($this->panel->client(), new NullLogger()));
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
     * A user's detail as GET /api/user/{username} answers it (dto.go UserDetail): in service 3, its term waiting a month
     * for its first connection, its link the panel's path (no prefix set) of the default type, its key.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function user(array $overrides = []): array
    {
        $name = is_string($overrides['username'] ?? null) ? $overrides['username'] : 'amir_1';

        return $overrides + [
            'username' => $name,
            'credential_key' => self::CREDENTIAL,
            'key_subscription_url' => '/sub/' . self::CREDENTIAL,
            'status' => 'on_hold',
            'used_traffic' => 0,
            'lifetime_used_traffic' => 0,
            'created_at' => '2026-10-05T10:00:00Z',
            'expire' => null,
            'data_limit' => 30 * self::GIB,
            'data_limit_reset_strategy' => 'no_reset',
            'flow' => null,
            'note' => '42 | amir',
            'telegram_id' => null,
            'contact_number' => null,
            'sub_updated_at' => null,
            'sub_last_user_agent' => null,
            'online_at' => null,
            'on_hold_expire_duration' => self::MONTH,
            'on_hold_timeout' => null,
            'ip_limit' => 2,
            'speed_limit_kbps' => 0,
            'auto_delete_in_days' => null,
            'service_id' => 3,
            'service_name' => 'Germany',
            'admin_id' => 1,
            'admin_username' => 'admin',
            'proxies' => new \stdClass(),
            'excluded_inbounds' => new \stdClass(),
            'inbounds' => new \stdClass(),
            'next_plans' => [],
            'links' => ['vless://' . self::UUID . '@de1.example.com:443?security=reality&type=tcp#amir_1'],
            'subscription_url' => '/sub/' . self::CREDENTIAL,
            'subscription_urls' => self::links($name),
            'service_host_orders' => new \stdClass(),
            'group' => null,
            'tags' => [],
        ];
    }

    /**
     * A user as GET /api/users lists it (dto.go UserListItem): no device limit, no waiting term's duration.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function listed(array $overrides = []): array
    {
        $name = is_string($overrides['username'] ?? null) ? $overrides['username'] : 'amir_1';

        return $overrides + [
            'id' => 7,
            'username' => $name,
            'status' => 'active',
            'used_traffic' => 0,
            'lifetime_used_traffic' => 0,
            'created_at' => '2026-10-05T10:00:00Z',
            'expire' => 1893456000,
            'data_limit' => 30 * self::GIB,
            'data_limit_reset_strategy' => 'no_reset',
            'online_at' => null,
            'is_online' => false,
            'service_id' => 3,
            'service_name' => 'Germany',
            'admin_id' => 1,
            'admin_username' => 'admin',
            'links' => [],
            'subscription_url' => '/sub/' . self::CREDENTIAL,
            'subscription_urls' => self::links($name),
            'group' => null,
            'tags' => [],
        ];
    }

    /** @return array<string, string> A user's links but the primary (links.go BuildSubscriptionLinks), no prefix set. */
    private static function links(string $name): array
    {
        return [
            'username-key' => "/sub/{$name}/" . self::CREDENTIAL,
            'key' => '/sub/' . self::CREDENTIAL,
            'key-username' => '/sub/' . self::CREDENTIAL . "#{$name}",
            'token' => '/sub/' . self::TOKEN,
        ];
    }

    /**
     * A page of GET /api/users (dto.go UsersResponse).
     *
     * @param list<array<string, mixed>> $users
     * @return array<string, mixed>
     */
    private static function page(array $users, ?int $total = null): array
    {
        return ['users' => $users, 'link_templates' => new \stdClass(), 'total' => $total ?? count($users), 'active_total' => count($users), 'status_breakdown' => ['active' => count($users)], 'usage_total' => 0, 'online_total' => 0];
    }

    /** @return array<string, mixed> What a subscription route's /info answers (subscription.go SubscriptionInfo): the user under `user`. */
    private static function info(string $name): array
    {
        return ['user' => ['username' => $name, 'status' => 'active', 'used_traffic' => 0, 'data_limit' => 30 * self::GIB], 'ov_links' => [], 'wg_links' => [], 'wg_downloads' => [], 'awg_downloads' => [], 'l2tp' => [], 'pptp' => []];
    }

    /** @return array<string, mixed> GET /api/admin (admin_auth.go adminResponse): the key's admin and its role. */
    private static function admin(string $role): array
    {
        return ['id' => 1, 'username' => 'admin', 'created_by' => null, 'role' => $role, 'permissions' => ['users' => ['create' => true]], 'services' => [], 'status' => 'active', 'user_speed_limit_enabled' => true];
    }

    /**
     * A service as GET /api/v2/services lists it (services.go serviceBaseResponse).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function panelService(array $overrides): array
    {
        return $overrides + ['id' => 1, 'name' => 'Service', 'description' => null, 'usage_coefficient' => 1.0, 'used_traffic' => 0, 'lifetime_used_traffic' => 0, 'host_count' => 1, 'user_count' => 0, 'has_hosts' => true, 'broken' => false];
    }

    /**
     * GET /api/system (internal/app/system/types.go SystemStats), what the shop reads of it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function system(array $overrides = []): array
    {
        return $overrides + [
            'version' => '1.9.1',
            'cpu_cores' => 4,
            'cpu_usage' => 12.5,
            'total_user' => 130,
            'online_users' => 12,
            'memory' => ['current' => 2 * self::GIB, 'total' => 8 * self::GIB, 'percent' => 25.0],
            'swap' => ['current' => 0, 'total' => 0, 'percent' => 0.0],
            'disk' => ['current' => 20 * self::GIB, 'total' => 80 * self::GIB, 'percent' => 25.0],
            'uptime_seconds' => 86400,
            'xray_running' => true,
            'xray_version' => '25.10.31',
            'last_xray_error' => null,
        ];
    }

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body) . "\n");
    }

    private static function link(string $text): ClientLink
    {
        return ClientLink::find($text) ?? self::fail("No link in {$text}.");
    }
}
