<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Marzban;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\Drivers\Marzban\MarzbanApiException;
use App\Modules\Providers\Drivers\Marzban\MarzbanDriver;
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
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * The generic ProviderInterface on Marzban: users as clients, the inbounds of its Xray config as inbounds (a user made
 * with a proxy of each of their protocols), "on hold" for a term waiting for the first connection, and the signed link
 * made absolute. Every case signs in first (Marzban's API has no keys); the calls below are the ones after it.
 */
final class MarzbanProviderTest extends TestCase
{
    private const MONTH = 30 * 86400;

    /** A user's proxies with every protocol on: each one's secret, a vless flow and a Shadowsocks method beside them. */
    private const PROXIES = [
        'vless' => ['id' => 'b1c0e3f4-1234-4abc-8def-0123456789ab', 'flow' => 'xtls-rprx-vision'],
        'vmess' => ['id' => 'c2d1f4a5-1234-4abc-8def-0123456789ab'],
        'trojan' => ['password' => 'Tr0jan-Pass-1', 'flow' => ''],
        'shadowsocks' => ['password' => 'Sh4dow-Pass', 'method' => 'chacha20-ietf-poly1305'],
    ];

    /** A subscription token as Marzban signs one (base64url of "name,time" and ten characters of signature), and one it no longer takes. */
    private const TOKEN = 'YW1pcl8xLDE3NjAyNjI0MDA4f3Kq1ZbXy';
    private const REVOKED = 'cmV2b2tlZF8xLDE3NjAyNjI0MDA9gT2pLmNoQ';

    private FakePanel $panel;

    public function testANewClientIsAUserOnHoldWithAProxyOfEachProtocolOfItsInbounds(): void
    {
        $provider = $this->provider([self::json(200, self::inbounds()), self::json(200, self::user())]);

        $client = $provider->createClient(['VLESS TCP REALITY', 'VMess WS', 'VLESS TCP REALITY'], new ClientSpec(
            name: 'amir_1',
            totalBytes: 30 * 1024 ** 3,
            expiry: Expiry::afterFirstUse(self::MONTH),
            ipLimit: 2,
            telegramId: 42,
            comment: '42 | amir',
        ));

        self::assertSame(['GET /api/inbounds', 'POST /api/user'], $this->sent());
        self::assertSame('{"username":"amir_1","proxies":{"vless":{},"vmess":{}},"inbounds":{"vless":["VLESS TCP REALITY"],"vmess":["VMess WS"]},"data_limit":32212254720,"data_limit_reset_strategy":"no_reset","auto_delete_in_days":-1,"status":"on_hold","expire":0,"on_hold_expire_duration":2592000,"note":"42 | amir"}', (string) $this->call(1)->getBody(), 'settings left for the panel to mint, each protocol an object; never deleted by the panel\'s own clean-up; no IP limit (the panel has none)');

        self::assertSame('amir_1', $client->name);
        self::assertTrue($client->enabled);
        self::assertSame(30 * 1024 ** 3, $client->totalBytes);
        self::assertSame(self::MONTH, $client->expiry->pendingSeconds());
        self::assertSame('https://mz.example:8000/sub/dGVzdC10b2tlbi1zaWduZWQx', $client->subscriptionUrl, 'a path is resolved against the panel');
        self::assertNull($client->online);
        self::assertNull($client->ipLimit, 'Marzban keeps no IP limit');
    }

    public function testAnInboundThePanelNoLongerHasIsRefusedBeforeAnythingIsMade(): void
    {
        $provider = $this->provider([self::json(200, self::inbounds())]);

        try {
            $provider->createClient(['VLESS TCP REALITY', 'Old WS'], new ClientSpec('amir_1'));
            self::fail('the inbound is gone');
        } catch (MarzbanApiException $e) {
            self::assertSame("Inbound Old WS doesn't exist", $e->panelMessage, 'in Marzban\'s own words');
            self::assertStringContainsString('Core Settings', $e->hint);
            self::assertFalse($e->mayHaveBeenDone());
        }
        self::assertSame(['GET /api/inbounds'], $this->sent());
    }

    public function testADeadlineIsAnExpireInUnixSecondsAndNoTermIsExpireZero(): void
    {
        $deadline = new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC'));
        $provider = $this->provider([
            self::json(200, self::inbounds()),
            self::json(200, self::user(['status' => 'active', 'expire' => $deadline->getTimestamp(), 'on_hold_expire_duration' => null])),
            self::json(200, self::inbounds()),
            self::json(200, self::user(['status' => 'active', 'on_hold_expire_duration' => null])),
        ]);

        $fixed = $provider->createClient(['VLESS TCP REALITY'], new ClientSpec('amir_1', expiry: Expiry::at($deadline)));
        $never = $provider->createClient(['VLESS TCP REALITY'], new ClientSpec('amir_2'));

        self::assertSame(['status' => 'active', 'expire' => $deadline->getTimestamp()], array_intersect_key($this->body(1), ['status' => 1, 'expire' => 1]));
        self::assertArrayNotHasKey('on_hold_expire_duration', $this->body(1));
        self::assertEquals($deadline, $fixed->expiry->deadline());
        self::assertSame(0, $this->body(3)['expire']);
        self::assertArrayNotHasKey('note', $this->body(3), 'nothing to say leaves the note alone');
        self::assertSame([null, null], [$never->expiry->deadline(), $never->expiry->pendingSeconds()]);
        self::assertSame([-1, -1], [$this->body(1)['auto_delete_in_days'], $this->body(3)['auto_delete_in_days']], 'never removed by the panel\'s own clean-up of ended users (crud.autodelete_expired_users skips negatives): the shop removes them itself');
    }

    public function testANoteIsCutToWhatMarzbanKeeps(): void
    {
        $provider = $this->provider([self::json(200, self::inbounds()), self::json(200, self::user())]);

        $provider->createClient(['VLESS TCP REALITY'], new ClientSpec('amir_1', comment: str_repeat('ی', 600)));

        self::assertSame(500, mb_strlen($this->body(1)['note']));
    }

    public function testAClientWantedSwitchedOffIsSwitchedOffAsItIsMade(): void
    {
        $provider = $this->provider([self::json(200, self::inbounds()), self::json(200, self::user()), self::json(200, self::user(['status' => 'disabled']))]);

        $client = $provider->createClient(['VLESS TCP REALITY'], new ClientSpec('amir_1', 10, Expiry::afterFirstUse(self::MONTH), enabled: false));

        self::assertSame(['GET /api/inbounds', 'POST /api/user', 'PUT /api/user/amir_1'], $this->sent(), 'Marzban makes a user active or on hold only');
        self::assertSame('on_hold', $this->body(1)['status']);
        self::assertSame(['status' => 'disabled'], $this->body(2), 'its waiting term left on it');
        self::assertFalse($client->enabled);
        self::assertSame(self::MONTH, $client->expiry->pendingSeconds());
    }

    public function testAUserThePanelWillNotSwitchOffIsTakenBackOrElseFailsAsMade(): void
    {
        $refusal = static fn(): Response => new Response(500, ['Content-Type' => 'text/plain'], 'Internal Server Error');
        $provider = $this->provider([self::json(200, self::inbounds()), self::json(200, self::user()), $refusal(), self::json(200, ['detail' => 'User successfully deleted'])]);
        try {
            $provider->createClient(['VLESS TCP REALITY'], new ClientSpec('amir_1', enabled: false));
            self::fail('the switch was refused');
        } catch (ProviderException $e) {
            self::assertSame(['GET /api/inbounds', 'POST /api/user', 'PUT /api/user/amir_1', 'DELETE /api/user/amir_1'], $this->sent());
        }

        $provider = $this->provider([self::json(200, self::inbounds()), self::json(200, self::user()), self::json(422, ['detail' => ['status' => 'bad']]), self::json(422, ['detail' => ['username' => 'bad']])]);
        try {
            $provider->createClient(['VLESS TCP REALITY'], new ClientSpec('amir_1', enabled: false));
            self::fail('the switch was refused');
        } catch (ProviderException $e) {
            self::assertTrue($e->mayHaveBeenDone(), 'still there, switched on: whoever asked looks at it');
        }
    }

    public function testCreateClientWithoutAnInboundIsACallerMistake(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->provider([])->createClient([], new ClientSpec('amir_1'));
    }

    public function testAnUpdateReadsTheUserThenReplacesTheLimitsAndTermInOnePut(): void
    {
        $provider = $this->provider([
            self::json(200, self::user()),
            self::json(200, self::user(['data_limit' => 60 * 1024 ** 3])),
            self::json(200, self::user()),
            self::json(200, self::user(['data_limit' => null, 'status' => 'active'])),
        ]);

        $client = $provider->updateClient(new ClientSpec('amir_1', 60 * 1024 ** 3, Expiry::afterFirstUse(2 * self::MONTH), comment: '42 | amir'));
        $provider->updateClient(new ClientSpec('amir_1'));

        self::assertSame(['GET /api/user/amir_1', 'PUT /api/user/amir_1', 'GET /api/user/amir_1', 'PUT /api/user/amir_1'], $this->sent());
        self::assertSame(['data_limit' => 60 * 1024 ** 3, 'data_limit_reset_strategy' => 'no_reset', 'status' => 'on_hold', 'expire' => 0, 'on_hold_expire_duration' => 2 * self::MONTH, 'note' => '42 | amir'], $this->body(1), 'proxies and inbounds left alone');
        self::assertSame(60 * 1024 ** 3, $client->totalBytes);
        // Unlimited and endless: 0 for each, the month it was waiting for overwritten; no comment leaves the note alone.
        self::assertSame(['data_limit' => 0, 'data_limit_reset_strategy' => 'no_reset', 'status' => 'active', 'expire' => 0, 'on_hold_expire_duration' => -1], $this->body(3));
    }

    public function testAnUpdateDropsAResetScheduleThePanelKept(): void
    {
        // An adopted user, its quota reset every month by the panel: the shop's quota is once a term.
        $monthly = self::user(['status' => 'active', 'expire' => 1893456000, 'on_hold_expire_duration' => null, 'data_limit_reset_strategy' => 'month']);
        $provider = $this->provider([self::json(200, $monthly), self::json(200, ['data_limit_reset_strategy' => 'no_reset'] + $monthly)]);

        $provider->updateClient(new ClientSpec('amir_1', 10, Expiry::at(new \DateTimeImmutable('@1893456000'))));

        self::assertSame(['data_limit' => 10, 'data_limit_reset_strategy' => 'no_reset', 'status' => 'active', 'expire' => 1893456000], $this->body(1), 'the panel\'s monthly reset replaced by none');
    }

    /**
     * Marzban's API cannot clear a duration (its validator reads 0 as "no change"), and its share and review code skip a
     * negative one: a term not waiting overwrites a duration the read user has with -1 — and only then. A term waiting
     * sends its own; a user with none, or one overwritten already, is sent none.
     */
    public function testATermNotWaitingOverwritesTheDurationTheUserHadWithMinusOne(): void
    {
        $deadline = Expiry::at(new \DateTimeImmutable('@1893456000'));
        foreach ([
            'a deadline, a month left from a term on hold' => [self::user(['status' => 'on_hold']), $deadline, -1],
            'endless, a month left on a user switched off' => [self::user(['status' => 'disabled']), Expiry::never(), -1],
            'a deadline, the user active with a leftover' => [self::user(['status' => 'active', 'expire' => 1890000000]), $deadline, -1],
            'a term waiting sends its own' => [self::user(['status' => 'active', 'expire' => 1890000000]), Expiry::afterFirstUse(2 * self::MONTH), 2 * self::MONTH],
            'no duration on the user' => [self::user(['status' => 'active', 'expire' => 1890000000, 'on_hold_expire_duration' => null]), $deadline, null],
            'a duration of 0 is none' => [self::user(['status' => 'active', 'expire' => 1890000000, 'on_hold_expire_duration' => 0]), $deadline, null],
            'overwritten already' => [self::user(['status' => 'active', 'expire' => 1890000000, 'on_hold_expire_duration' => -1]), $deadline, null],
        ] as $case => [$before, $expiry, $sent]) {
            $provider = $this->provider([self::json(200, $before), self::json(200, $before)]);

            $provider->updateClient(new ClientSpec('amir_1', 10, $expiry));

            self::assertSame(['GET /api/user/amir_1', 'PUT /api/user/amir_1'], $this->sent(), $case);
            self::assertSame($sent, $this->body(1)['on_hold_expire_duration'] ?? null, $case);
        }
    }

    public function testADurationOverwrittenWithMinusOneIsNoTermAndNeverPutBackOnHold(): void
    {
        $overwritten = self::user(['status' => 'disabled', 'expire' => null, 'on_hold_expire_duration' => -1, 'data_limit' => null]);
        $provider = $this->provider([self::json(200, $overwritten), self::json(200, $overwritten), self::json(200, ['status' => 'active'] + $overwritten)]);

        $client = $provider->findClient('amir_1');
        self::assertSame([null, null], [$client?->expiry->pendingSeconds(), $client?->expiry->deadline()], 'switched off, endless: no term waiting (waitingTerm() counts only a positive duration)');
        $provider->setClientEnabled('amir_1', true);

        self::assertSame(['GET /api/user/amir_1', 'GET /api/user/amir_1', 'PUT /api/user/amir_1'], $this->sent());
        self::assertSame(['status' => 'active', 'data_limit' => 0, 'expire' => 0], $this->body(2), 'active, unlimited and endless: never "on hold" with -1');
    }

    public function testEveryUpdateSendsBackTheNextPlanMarzbanWouldOtherwiseDrop(): void
    {
        $plan = ['data_limit' => 10 * 1024 ** 3, 'expire' => 2_592_000, 'add_remaining_traffic' => true, 'fire_on_either' => false];
        $planned = self::user(['status' => 'active', 'expire' => 1893456000, 'on_hold_expire_duration' => null, 'next_plan' => $plan + ['id' => 3, 'user_id' => 7]]);
        $provider = $this->provider([
            self::json(200, $planned), self::json(200, $planned),                               // updateClient
            self::json(200, $planned), self::json(200, $planned + ['status' => 'disabled']),    // setClientEnabled(off)
        ]);

        $provider->updateClient(new ClientSpec('amir_1', 1, Expiry::at(new \DateTimeImmutable('@1893456000'))));
        $provider->setClientEnabled('amir_1', false);

        self::assertSame($plan, $this->body(1)['next_plan'], 'the plan as Marzban takes it, nothing of its row');
        self::assertSame(['status' => 'disabled', 'next_plan' => $plan], $this->body(3));
    }

    public function testAnUpdateOfAUserKeptOffSendsItDisabledWithItsTerm(): void
    {
        $provider = $this->provider([
            self::json(200, self::user()),
            self::json(200, self::user(['status' => 'disabled'])),
            self::json(200, self::user(['status' => 'disabled'])),
            self::json(200, self::user(['status' => 'disabled', 'expire' => 1793491200, 'on_hold_expire_duration' => null])),
        ]);

        $client = $provider->updateClient(new ClientSpec('amir_1', 10, Expiry::afterFirstUse(self::MONTH), enabled: false));
        $provider->updateClient(new ClientSpec('amir_1', 10, Expiry::at(new \DateTimeImmutable('2026-11-01 00:00:00', new \DateTimeZone('UTC'))), enabled: false));

        // A term still waiting keeps its duration, for the switch to put it back on hold.
        self::assertSame(['data_limit' => 10, 'data_limit_reset_strategy' => 'no_reset', 'status' => 'disabled', 'expire' => 0, 'on_hold_expire_duration' => self::MONTH], $this->body(1));
        self::assertFalse($client->enabled);
        self::assertSame(self::MONTH, $client->expiry->pendingSeconds());
        // A deadline: the month the user was waiting for is overwritten.
        self::assertSame(['data_limit' => 10, 'data_limit_reset_strategy' => 'no_reset', 'status' => 'disabled', 'expire' => 1793491200, 'on_hold_expire_duration' => -1], $this->body(3));
    }

    public function testUpdatingAUserThePanelDoesNotHaveIsNotFound(): void
    {
        $provider = $this->provider([self::json(404, ['detail' => 'User not found'])]);

        $this->expectException(NotFoundException::class);

        $provider->updateClient(new ClientSpec('gone_1'));
    }

    public function testFindClientReadsTheCountersTheTermAndWhetherItWasSeenInTheLastMinute(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $provider = $this->provider([
            self::json(200, self::user(['status' => 'active', 'used_traffic' => 5 * 1024 ** 3, 'expire' => 1791115140, 'on_hold_expire_duration' => null, 'online_at' => '2026-10-05T11:59:30'])),
            self::json(200, self::user(['status' => 'disabled', 'data_limit' => null])),
            self::json(200, self::user(['status' => 'active', 'expire' => 1791115140, 'on_hold_expire_duration' => null, 'online_at' => '2026-10-05T11:58:50.123456'])),
            self::json(200, self::user(['status' => 'expired', 'expire' => 1790812800, 'on_hold_expire_duration' => null])),
            self::json(200, self::user(['status' => 'limited', 'used_traffic' => 30 * 1024 ** 3, 'expire' => 1791115140, 'on_hold_expire_duration' => null])),
        ]);

        $running = $provider->findClient('amir_1', presence: true);
        self::assertNotNull($running);
        self::assertSame('GET /api/user/amir_1', $this->sent()[0]);
        self::assertSame([0, 5 * 1024 ** 3], [$running->uploadBytes, $running->downloadBytes], 'one total: Marzban has no upload/download split');
        self::assertSame(1791115140, $running->expiry->deadline()?->getTimestamp());
        self::assertTrue($running->online);
        self::assertSame('2026-10-05 11:59:30', $running->lastOnlineAt?->format('Y-m-d H:i:s'), 'a moment without a zone is UTC');

        $switchedOff = $provider->findClient('amir_1', presence: true);
        self::assertNotNull($switchedOff);
        self::assertFalse($switchedOff->enabled);
        self::assertSame(0, $switchedOff->totalBytes, 'no data limit = unlimited');
        self::assertSame(self::MONTH, $switchedOff->expiry->pendingSeconds(), 'switched off before its first connection, the term still waits for it');
        self::assertFalse($switchedOff->online, 'never seen');

        self::assertFalse($provider->findClient('amir_1', presence: true)?->online, 'seventy seconds ago is not online');

        $ended = $provider->findClient('amir_1');
        self::assertNotNull($ended);
        self::assertTrue($ended->enabled, 'expired is ended by its term, not switched off');
        self::assertSame('2026-10-01', $ended->expiry->deadline()?->format('Y-m-d'));
        self::assertNull($ended->online, 'presence not asked: nobody can tell');

        $limited = $provider->findClient('amir_1');
        self::assertTrue($limited?->enabled, 'limited is ended by its traffic, not switched off');
    }

    /**
     * Marzban's API cannot clear a duration: one stays on a user whose term was replaced. It is never a term on an active
     * or limited user, nor beside a deadline. On one on hold or switched off it is waiting — whether or not the user
     * connected before: a renewed ended service goes back on hold, and its term starts at its next connection.
     */
    public function testALeftoverDurationIsNoWaitingTermButOneOnHoldOrSwitchedOffIs(): void
    {
        $duration = ['expire' => null, 'on_hold_expire_duration' => self::MONTH];
        $provider = $this->provider([
            self::json(200, self::user($duration + ['status' => 'on_hold', 'online_at' => '2026-10-05T11:00:00'])),
            self::json(200, self::user($duration + ['status' => 'disabled', 'online_at' => null])),
            self::json(200, self::user($duration + ['status' => 'disabled', 'online_at' => '2026-10-05T11:00:00'])),
            self::json(200, self::user($duration + ['status' => 'active', 'online_at' => null])),
            self::json(200, self::user($duration + ['status' => 'limited', 'online_at' => null])),
            self::json(200, self::user(['status' => 'disabled', 'expire' => 1893456000, 'on_hold_expire_duration' => self::MONTH, 'online_at' => null])),
        ]);

        $terms = array_map(static fn(): ?int => $provider->findClient('amir_1')?->expiry->pendingSeconds(), range(1, 6));

        self::assertSame([self::MONTH, self::MONTH, self::MONTH, null, null, null], $terms, 'on hold; off, never connected or renewed after connecting; then leftovers: active, limited, a deadline');
    }

    public function testListClientsPagesThroughEveryUserByTheirUniqueName(): void
    {
        $first = array_map(static fn(int $i): array => self::user(['username' => "user_{$i}"]), range(1, 500));
        $provider = $this->provider([
            self::admin(sudo: true),
            self::json(200, ['users' => $first, 'total' => 501]),
            self::json(200, ['users' => [self::user(['username' => 'user_501', 'subscription_url' => 'https://sub.example.com/sub/abcdefghijklmnop'])], 'total' => 501]),
        ]);

        $clients = $provider->listClients();

        self::assertCount(501, $clients);
        self::assertSame(['GET /api/admin', 'GET /api/users', 'GET /api/users'], $this->sent(), 'a sudo admin, which sees every user');
        self::assertSame('offset=0&limit=500&sort=username', $this->call(1)->getUri()->getQuery());
        self::assertSame('offset=500&limit=500&sort=username', $this->call(2)->getUri()->getQuery());
        self::assertSame('user_501', $clients[500]->name);
        self::assertSame('https://sub.example.com/sub/abcdefghijklmnop', $clients[500]->subscriptionUrl, 'a panel with a URL prefix answers a full link: kept as it is');
        self::assertNull($clients[0]->online, 'a list says nothing about presence');
    }

    public function testANonSudoAdminsPanelIsNotListedAtOnceAndIsAskedOnce(): void
    {
        $provider = $this->provider([self::admin(sudo: false)]);

        foreach ([1, 2] as $try) {
            try {
                $provider->listClients();
                self::fail('a non-sudo admin sees only the users it made');
            } catch (UnsupportedOperationException $e) {
                self::assertStringContainsString('sudo', $e->getMessage());
            }
        }

        self::assertSame(['GET /api/admin'], $this->sent(), 'asked once for the client\'s life; the sync then asks for each service');
    }

    public function testAPageWithoutATotalPagesOnUntilAShortOneAndAShortOneIsTheLast(): void
    {
        $full = self::json(200, ['users' => array_map(static fn(int $i): array => self::user(['username' => "user_{$i}"]), range(1, 500))]);
        $provider = $this->provider([self::admin(sudo: true), $full, self::json(200, ['users' => [self::user(['username' => 'user_501'])]])]);

        self::assertCount(501, $provider->listClients(), 'no total is no "zero users": the next page is asked for');
        self::assertCount(3, $this->sent());

        $provider = $this->provider([self::admin(sudo: true), self::json(200, ['users' => [self::user(), self::user(['username' => 'amir_2'])], 'total' => 900])]);
        self::assertSame(['amir_1', 'amir_2'], array_map(static fn(ClientInfo $client): string => $client->name, $provider->listClients()));
        self::assertCount(2, $this->sent(), 'a total that counts users the page does not show: the short page ends it');
    }

    public function testAPanelThatNeverStopsAnsweringFullPagesIsNotListedAtOnce(): void
    {
        $page = self::json(200, ['users' => array_map(static fn(int $i): array => ['username' => "user_{$i}", 'status' => 'active'], range(1, 500)), 'total' => 1_000_000]);
        $provider = $this->provider([self::admin(sudo: true), ...array_fill(0, 100, $page)]);

        try {
            $provider->listClients();
            self::fail('expected UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            self::assertStringContainsString('50000 users', $e->getMessage());
        }

        self::assertCount(101, $this->sent(), 'the sync then asks for each service instead');
    }

    public function testAConfigIsTheUserWhoseProxiesHoldItsCredentialThenReadByName(): void
    {
        $first = array_map(static fn(int $i): array => self::user(['username' => "user_{$i}", 'proxies' => ['vless' => ['id' => sprintf('00000000-0000-4000-8000-%012d', $i), 'flow' => '']]]), range(1, 500));
        $provider = $this->provider([
            self::json(200, ['users' => $first, 'total' => 502]),
            self::json(200, ['users' => [self::user(['username' => 'amir_501', 'proxies' => self::PROXIES]), self::user(['username' => 'amir_502'])], 'total' => 502]),
            self::json(200, self::user(['username' => 'amir_501'])), // the user found, read on its own
        ]);

        $client = $provider->findClientByLink(self::link('trojan://Tr0jan-Pass-1@mz.example:443?sni=mz.example#amir'));

        self::assertSame(['GET /api/users', 'GET /api/users', 'GET /api/user/amir_501'], $this->sent(), 'the paging stops at the user found, then reads it');
        self::assertSame('amir_501', $client?->name);
        self::assertSame('https://mz.example:8000/sub/dGVzdC10b2tlbi1zaWduZWQx', $client->subscriptionUrl);
    }

    public function testEveryProtocolsSecretIsACredentialButNothingElseOfItsSettings(): void
    {
        $sip002 = 'ss://' . rtrim(strtr(base64_encode('chacha20-ietf-poly1305:Sh4dow-Pass'), '+/', '-_'), '=') . '@mz.example:8388#amir';
        foreach ([
            'vless, its id' => ['vless://B1C0E3F4-1234-4ABC-8DEF-0123456789AB@mz.example:443?flow=xtls-rprx-vision', 'amir_1'],
            'vmess, its id' => ['vmess://c2d1f4a5-1234-4abc-8def-0123456789ab@mz.example:443', 'amir_1'],
            'trojan, its password' => ['trojan://Tr0jan-Pass-1@mz.example:443', 'amir_1'],
            'Shadowsocks, its password' => [$sip002, 'amir_1'],
            'a flow' => ['trojan://xtls-rprx-vision@mz.example:443', null],
            'a method' => ['trojan://chacha20-ietf-poly1305@mz.example:443', null],
        ] as $case => [$config, $name]) {
            $provider = $this->provider([self::json(200, ['users' => [self::user(['proxies' => self::PROXIES])], 'total' => 1]), self::json(200, self::user())]);

            self::assertSame($name, $provider->findClientByLink(self::link($config))?->name, $case);
            self::assertSame($name === null ? ['GET /api/users'] : ['GET /api/users', 'GET /api/user/amir_1'], $this->sent(), $case);
        }
    }

    public function testASubscriptionLinksTokenIsShownToThePanelsOwnRouteWithoutTheAdminsToken(): void
    {
        $provider = $this->provider([
            // One user, read for the path its links live under.
            self::json(200, ['users' => [self::user(['username' => 'zed_9', 'subscription_url' => 'https://sub.example.com/sub/emVkXzksMTc2MDI2MjQwMAabcdefghij'])], 'total' => 40]),
            self::json(200, ['username' => 'amir_1', 'status' => 'active', 'used_traffic' => 0]), // /sub/{token}/info
            self::json(200, self::user()),                                                        // the user it names
        ]);

        $client = $provider->findClientByLink(self::link('سرویسم: https://sub.example.com/sub/' . self::TOKEN . '/v2ray-json'));

        self::assertSame([
            'GET /api/users',
            'GET /sub/' . self::TOKEN . '/info',
            'GET /api/user/amir_1',
        ], $this->sent(), 'the token alone: the format after it is too short to be one');
        self::assertSame('offset=0&limit=1', $this->call(0)->getUri()->getQuery());
        self::assertSame('mz.example', $this->call(1)->getUri()->getHost(), 'the panel\'s own address, whatever host its links name');
        self::assertFalse($this->call(1)->hasHeader('Authorization'), 'a client app\'s request carries no admin\'s token');
        self::assertStringStartsWith('Bearer ', $this->call(2)->getHeaderLine('Authorization'));
        self::assertSame('amir_1', $client?->name);
    }

    public function testAPrefixTheOwnerPutBeforeTheLinksIsNotThePanelsPath(): void
    {
        $own = 'dGVzdC10b2tlbi1zaWduZWQx';
        $provider = $this->provider([
            // Links under the panel's XRAY_SUBSCRIPTION_URL_PREFIX with a path of its own: the panel serves them at /sub.
            self::json(200, ['users' => [self::user(['subscription_url' => "https://cdn.example.com/vpn/links/sub/{$own}"])], 'total' => 1]),
            self::json(404, ['detail' => 'Not Found']),  // /vpn/links/sub: the owner's prefix
            self::json(404, ['detail' => 'Not Found']),  // /links/sub
            self::json(200, ['username' => 'amir_1']),   // /sub: the user whose link it is
            self::json(200, ['username' => 'amir_1']),   // the customer's token
            self::json(200, self::user()),
        ]);

        self::assertSame('amir_1', $provider->findClientByLink(self::link('https://cdn.example.com/vpn/links/sub/' . self::TOKEN))?->name);
        self::assertSame([
            'GET /api/users',
            "GET /vpn/links/sub/{$own}/info",
            "GET /links/sub/{$own}/info",
            "GET /sub/{$own}/info",
            'GET /sub/' . self::TOKEN . '/info',
            'GET /api/user/amir_1',
        ], $this->sent(), 'the folders before the token tried longest first, with the listed user\'s own token: the one answering with that user is the panel\'s');
        foreach ([1, 2, 3, 4] as $public) {
            self::assertFalse($this->call($public)->hasHeader('Authorization'), 'asked as a client app asks');
        }
    }

    public function testASubscriptionPathOfSeveralFoldersIsFound(): void
    {
        $own = 'dGVzdC10b2tlbi1zaWduZWQx';
        $provider = $this->provider([
            // The panel's XRAY_SUBSCRIPTION_PATH is "my/subs", its links paths.
            self::json(200, ['users' => [self::user(['username' => 'zed_9', 'subscription_url' => "/my/subs/{$own}"])], 'total' => 1]),
            self::json(200, ['username' => 'zed_9']),
            self::json(200, ['username' => 'amir_1']),
            self::json(200, self::user()),
        ]);

        self::assertSame('amir_1', $provider->findClientByLink(self::link('https://mz.example:8000/my/subs/' . self::TOKEN))?->name);
        self::assertSame(['GET /api/users', "GET /my/subs/{$own}/info", 'GET /my/subs/' . self::TOKEN . '/info', 'GET /api/user/amir_1'], $this->sent());

        // No folder answering with the listed user: no path, so no customer's token is shown anywhere.
        $provider = $this->provider([
            self::json(200, ['users' => [self::user(['subscription_url' => "/a/b/c/d/{$own}"])], 'total' => 1]),
            self::json(404, ['detail' => 'Not Found']),
            self::json(200, ['username' => 'someone_else']),
            self::json(404, ['detail' => 'Not Found']),
        ]);
        self::assertNull($provider->findClientByLink(self::link('https://mz.example:8000/a/b/c/d/' . self::TOKEN)));
        self::assertSame(['GET /api/users', "GET /b/c/d/{$own}/info", "GET /c/d/{$own}/info", "GET /d/{$own}/info"], $this->sent(), 'the last three folders at most');
    }

    public function testTheConfigIsLookedForOnceTheLinksTokenIsNobodys(): void
    {
        $provider = $this->provider([
            self::json(200, ['users' => [self::user()], 'total' => 1]),                             // the path
            self::json(404, ['detail' => 'Not Found']),                                             // a revoked link's token
            self::json(200, ['users' => [self::user(['proxies' => self::PROXIES])], 'total' => 1]), // the users, for the config
            self::json(200, self::user()),
        ]);

        self::assertSame('amir_1', $provider->findClientByLink(self::link('https://mz.example:8000/sub/' . self::REVOKED . "\nvless://B1C0E3F4-1234-4ABC-8DEF-0123456789AB@1.2.3.4:443"))?->name);
        self::assertSame(['GET /api/users', 'GET /sub/' . self::REVOKED . '/info', 'GET /api/users', 'GET /api/user/amir_1'], $this->sent());
    }

    public function testThreeTokensAtMostAreShownToThePanelAndNothingThatCannotBeOne(): void
    {
        [$a, $b, $c, $d] = array_map(static fn(string $letter): string => str_repeat($letter, 20), ['a', 'b', 'c', 'd']);
        $provider = $this->provider([
            self::json(200, ['users' => [self::user()], 'total' => 1]),
            self::json(404, ['detail' => 'Not Found']),
            self::json(404, ['detail' => 'Not Found']),
            self::json(404, ['detail' => 'Not Found']),
        ]);

        self::assertNull($provider->findClientByLink(self::link("https://mz.example:8000/{$a}/{$b}\nhttps://mz.example:8000/{$c}/{$d}")));
        self::assertSame(['GET /api/users', "GET /sub/{$b}/info", "GET /sub/{$a}/info", "GET /sub/{$d}/info"], $this->sent(), 'each a request: the fourth is never asked');

        foreach ([
            'shorter than Marzban reads (15)' => 'https://mz.example:8000/sub/cmV2b2tlZA.c2l',
            'four dotted parts' => 'https://mz.example:8000/sub/aaaaaa.bbbbbb.cccccc.dddddd',
            'padding' => 'https://mz.example:8000/sub/YWJjZGVmZ2hpamtsbW5vcA%3D%3D',
        ] as $case => $link) {
            $provider = $this->provider([]);

            self::assertNull($provider->findClientByLink(self::link($link)), $case);
            self::assertSame([], $this->panel->calls(), "{$case}: not even signed in for");
        }
    }

    public function testWithoutAUserToReadThereIsNoSubscriptionPathToAsk(): void
    {
        foreach ([
            'no user' => ['users' => [], 'total' => 0],
            'a link at the root' => ['users' => [self::user(['subscription_url' => '/dGVzdC10b2tlbi1zaWduZWQx'])], 'total' => 1],
        ] as $case => $answer) {
            $provider = $this->provider([self::json(200, $answer)]);

            self::assertNull($provider->findClientByLink(self::link('https://mz.example:8000/sub/' . self::TOKEN)), $case);
            self::assertSame(['GET /api/users'], $this->sent(), $case);
        }
    }

    public function testNothingAnsweringTheRouteFailsWithoutTheTokenInWhatItSays(): void
    {
        $address = 'https://mz.example:8000/sub/' . self::TOKEN . '/info';
        $provider = $this->provider([self::json(200, ['users' => [self::user()], 'total' => 1])]);
        $this->panel->refuse(new ConnectException("cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for {$address}", new Request('GET', $address)));

        try {
            $provider->findClientByLink(self::link('https://mz.example:8000/sub/' . self::TOKEN));
            self::fail('nothing answered');
        } catch (ProviderException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public function testSwitchingReadsTheUserAndSwitchingOnKeepsATermThatNeverStarted(): void
    {
        $provider = $this->provider([
            self::json(200, self::user()), self::json(200, self::user(['status' => 'disabled'])),
            self::json(200, self::user(['status' => 'disabled'])), self::json(200, self::user()),
            self::json(200, self::user(['status' => 'disabled', 'expire' => 1793491200, 'on_hold_expire_duration' => null])), self::json(200, self::user(['status' => 'active'])),
            self::json(200, self::user(['status' => 'active'])),
            self::json(200, self::user(['status' => 'disabled'])),
        ]);

        $provider->setClientEnabled('amir_1', false);
        self::assertSame(['GET /api/user/amir_1', 'PUT /api/user/amir_1'], $this->sent());
        self::assertSame(['status' => 'disabled'], $this->body(1));

        // Never connected: back on hold with its term — plain "active" would be endless.
        $provider->setClientEnabled('amir_1', true);
        self::assertSame(['status' => 'on_hold', 'on_hold_expire_duration' => self::MONTH], $this->body(3));

        // Its clock had started: active with its own limit and deadline, for the panel to judge at once.
        $provider->setClientEnabled('amir_1', true);
        self::assertSame(['status' => 'active', 'data_limit' => 30 * 1024 ** 3, 'expire' => 1793491200], $this->body(5));

        // As asked already, on or off: nothing to write.
        $provider->setClientEnabled('amir_1', true);
        $provider->setClientEnabled('amir_1', false);
        self::assertSame(['GET /api/user/amir_1', 'GET /api/user/amir_1'], array_slice($this->sent(), 6));
    }

    public function testAnUnlimitedServiceSwitchedBackOnWithItsTermStaysUnlimited(): void
    {
        // On hold once, then given no end, then switched off: the duration the API cannot clear stays on it.
        $leftover = ['expire' => null, 'on_hold_expire_duration' => self::MONTH];
        $provider = $this->provider([
            self::json(200, self::user($leftover + ['status' => 'disabled'])),
            self::json(200, self::user($leftover + ['status' => 'active'])),
        ]);

        $client = $provider->updateClient(new ClientSpec('amir_1', 0, Expiry::never()));

        self::assertSame(['data_limit' => 0, 'data_limit_reset_strategy' => 'no_reset', 'status' => 'active', 'expire' => 0, 'on_hold_expire_duration' => -1], $this->body(1), 'Marzban takes "active" as asked; the leftover overwritten');
        self::assertTrue($client->enabled);
        self::assertSame([null, null], [$client->expiry->deadline(), $client->expiry->pendingSeconds()], 'no end at all, the leftover no term');
    }

    /**
     * Marzban's update_user judges a user limited or expired only by the fields sent: one switched back on is sent its own
     * limit and deadline with "active", so a service used up or ended while it was off is limited or expired at once —
     * not at the panel's next review, connected meanwhile. None is 0; its next plan goes back with them.
     */
    public function testSwitchingOnSendsTheUsersOwnLimitAndDeadlineForThePanelToJudge(): void
    {
        $plan = ['data_limit' => 5, 'expire' => 86_400, 'add_remaining_traffic' => false, 'fire_on_either' => true];
        foreach ([
            'used up while off' => [['used_traffic' => 30 * 1024 ** 3, 'expire' => 1893456000], ['status' => 'active', 'data_limit' => 30 * 1024 ** 3, 'expire' => 1893456000]],
            'ended while off' => [['expire' => 1790812800], ['status' => 'active', 'data_limit' => 30 * 1024 ** 3, 'expire' => 1790812800]],
            'unlimited and endless' => [['data_limit' => null, 'expire' => null], ['status' => 'active', 'data_limit' => 0, 'expire' => 0]],
            'a deadline of 0 is none' => [['data_limit' => 0, 'expire' => 0], ['status' => 'active', 'data_limit' => 0, 'expire' => 0]],
            'its next plan sent back' => [['expire' => 1893456000, 'next_plan' => $plan + ['id' => 1, 'user_id' => 7]], ['status' => 'active', 'data_limit' => 30 * 1024 ** 3, 'expire' => 1893456000, 'next_plan' => $plan]],
        ] as $case => [$user, $sent]) {
            $off = self::user($user + ['status' => 'disabled', 'on_hold_expire_duration' => null]);
            $provider = $this->provider([self::json(200, $off), self::json(200, ['status' => 'active'] + $off)]);

            $provider->setClientEnabled('amir_1', true);

            self::assertSame(['GET /api/user/amir_1', 'PUT /api/user/amir_1'], $this->sent(), $case);
            self::assertSame($sent, $this->body(1), $case);
        }
    }

    public function testARenewedServiceSwitchedOffBeforeItReconnectedGoesBackOnHold(): void
    {
        // It connected before; its renewal put it back on hold; its term starts at its next connection.
        $provider = $this->provider([
            self::json(200, self::user(['status' => 'disabled', 'online_at' => '2026-10-05T11:00:00'])),
            self::json(200, self::user()),
        ]);

        $provider->setClientEnabled('amir_1', true);

        self::assertSame(['status' => 'on_hold', 'on_hold_expire_duration' => self::MONTH], $this->body(1), 'an old connection is no start of its term');
    }

    public function testDeleteReadsTheUserThenDeletesItByName(): void
    {
        $provider = $this->provider([self::json(200, self::user(['status' => 'active'])), self::json(200, ['detail' => 'User successfully deleted']), self::json(404, ['detail' => 'User not found'])]);

        $provider->deleteClient('amir_1');
        self::assertSame(['GET /api/user/amir_1', 'DELETE /api/user/amir_1'], $this->sent(), 'a user an admin owns: deleted at once');
        self::assertSame('', (string) $this->call(1)->getBody(), 'the delete takes no body');

        try {
            $provider->deleteClient('amir_1');
            self::fail('the user is gone');
        } catch (NotFoundException $e) {
            self::assertStringContainsString('User not found', $e->getMessage(), 'the contract\'s "not found"');
        }
        self::assertSame(['GET /api/user/amir_1', 'DELETE /api/user/amir_1', 'GET /api/user/amir_1'], $this->sent(), 'a user the panel does not have: nothing deleted');
    }

    /**
     * Marzban 0.8.2–0.8.4 deletes a user no admin owns (`admin: null`: made by the `.env` sudo admin, or its admin removed)
     * from its database, then fails the request (routers/user.py: `Admin.model_validate(dbuser.admin)`, its issue #1634)
     * before taking it off Xray. Such a user is switched off first — which takes it off Xray —, its next plan sent back in
     * case the delete fails; one switched off already is deleted at once.
     */
    public function testAUserNoAdminOwnsIsSwitchedOffBeforeItIsDeleted(): void
    {
        $plan = ['data_limit' => 1, 'expire' => 2, 'add_remaining_traffic' => false, 'fire_on_either' => true];
        foreach (['active', 'on_hold', 'limited', 'expired'] as $status) {
            $ownerless = self::user(['status' => $status, 'admin' => null, 'next_plan' => $plan + ['id' => 3, 'user_id' => 7]]);
            $provider = $this->provider([self::json(200, $ownerless), self::json(200, ['status' => 'disabled'] + $ownerless), self::json(200, ['detail' => 'User successfully deleted'])]);

            $provider->deleteClient('amir_1');

            self::assertSame(['GET /api/user/amir_1', 'PUT /api/user/amir_1', 'DELETE /api/user/amir_1'], $this->sent(), $status);
            self::assertSame(['status' => 'disabled', 'next_plan' => $plan], $this->body(1), $status);
        }

        $provider = $this->provider([self::json(200, self::user(['status' => 'disabled', 'admin' => null])), self::plainError(), self::json(404, ['detail' => 'User not found'])]);

        $provider->deleteClient('amir_1');

        self::assertSame(['GET /api/user/amir_1', 'DELETE /api/user/amir_1', 'GET /api/user/amir_1'], $this->sent(), 'switched off already: off Xray, nothing to switch');
    }

    /** Marzban's own 500 is the framework's plain text: after it, a user the panel no longer has is deleted. */
    public function testAServerErrorAfterWhichTheUserIsGoneIsTheDeleteDone(): void
    {
        $ownerless = self::user(['status' => 'active', 'admin' => null]);
        $provider = $this->provider([self::json(200, $ownerless), self::json(200, ['status' => 'disabled'] + $ownerless), self::plainError(), self::json(404, ['detail' => 'User not found'])]);

        $provider->deleteClient('amir_1');

        self::assertSame(['GET /api/user/amir_1', 'PUT /api/user/amir_1', 'DELETE /api/user/amir_1', 'GET /api/user/amir_1'], $this->sent());
    }

    public function testAServerErrorAfterWhichTheUserIsStillThereFailsAsItCame(): void
    {
        foreach ([
            'the panel\'s own' => self::plainError(),
            'a proxy\'s page' => new Response(502, ['Content-Type' => 'text/html'], '<html><body><h1>502 Bad Gateway</h1></body></html>'),
        ] as $case => $error) {
            $owned = self::user(['status' => 'active']);
            $provider = $this->provider([self::json(200, $owned), $error, self::json(200, $owned)]);

            try {
                $provider->deleteClient('amir_1');
                self::fail("{$case}: the user is still there");
            } catch (UnexpectedResponseException $e) {
                self::assertSame($error->getStatusCode(), $e->httpStatus, $case);
                self::assertTrue($e->mayHaveBeenDone(), "{$case}: as it came");
            }
            self::assertSame(['GET /api/user/amir_1', 'DELETE /api/user/amir_1', 'GET /api/user/amir_1'], $this->sent(), $case);
        }
    }

    /**
     * KNOWN DEFECT (MarzbanProvider::deleteClient(), the look after a 5xx): a look that fails otherwise than "User not
     * found" — the panel restarting after its 500, its port refusing — is thrown in place of the delete's 5xx, and a
     * connection never made says the delete was not done (ConnectionException::mayHaveBeenDone()), though the DELETE's
     * answer said it may have been: ProvisioningService::move() would then take the new client back while the old may be
     * gone. The delete's 5xx is what goes on.
     */
    public function testALookThatCannotReachThePanelAfterAServerErrorStillSaysTheDeleteMayHaveBeenDone(): void
    {
        $ownerless = self::user(['status' => 'active', 'admin' => null]);
        $provider = $this->provider([self::json(200, $ownerless), self::json(200, ['status' => 'disabled'] + $ownerless), self::plainError()]);
        $this->panel->refuse(new ConnectException('cURL error 7: Failed to connect to mz.example port 8000 after 0 ms: Connection refused', new Request('GET', 'https://mz.example:8000/api/user/amir_1')));

        try {
            $provider->deleteClient('amir_1');
            self::fail('nobody could say whether the user is gone');
        } catch (ProviderException $e) {
            self::assertSame(['GET /api/user/amir_1', 'PUT /api/user/amir_1', 'DELETE /api/user/amir_1', 'GET /api/user/amir_1'], $this->sent());
            self::assertInstanceOf(UnexpectedResponseException::class, $e, 'the delete\'s own answer');
            self::assertTrue($e->mayHaveBeenDone());
        }
    }

    public function testARefusedDeleteFailsWithoutAskingAgain(): void
    {
        foreach ([
            'the panel\'s refusal' => [self::json(403, ['detail' => "You're not allowed"]), MarzbanApiException::class],
            'something in front of the panel refusing the method' => [new Response(405, ['Content-Type' => 'text/html'], '<html><body>405 Not Allowed</body></html>'), UnexpectedResponseException::class],
        ] as $case => [$refusal, $type]) {
            $provider = $this->provider([self::json(200, self::user(['status' => 'active'])), $refusal]);

            try {
                $provider->deleteClient('amir_1');
                self::fail("{$case}: refused");
            } catch (ProviderException $e) {
                self::assertInstanceOf($type, $e, $case);
                self::assertFalse($e->mayHaveBeenDone(), "{$case}: a 4xx is no delete");
            }
            self::assertSame(['GET /api/user/amir_1', 'DELETE /api/user/amir_1'], $this->sent(), $case);
        }
    }

    public function testAnotherAdminsUserIsRefusedAsItIsReadAndNothingIsDeleted(): void
    {
        $provider = $this->provider([self::json(403, ['detail' => "You're not allowed"])]);

        try {
            $provider->deleteClient('amir_1');
            self::fail('a non-sudo admin sees only its own users');
        } catch (MarzbanApiException $e) {
            self::assertSame(403, $e->httpStatus);
            self::assertStringContainsString('sudo', $e->hint);
        }
        self::assertSame(['GET /api/user/amir_1'], $this->sent());
    }

    public function testAUserThePanelWillNotSwitchOffIsNotDeleted(): void
    {
        $provider = $this->provider([self::json(200, self::user(['status' => 'active', 'admin' => null])), self::plainError()]);

        try {
            $provider->deleteClient('amir_1');
            self::fail('the switch failed');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(500, $e->httpStatus);
        }
        self::assertSame(['GET /api/user/amir_1', 'PUT /api/user/amir_1'], $this->sent(), 'still on Xray: not deleted from under it');
    }

    /**
     * Marzban's reset makes every user but an expired one active (its status test reads the word "expired"): a
     * switched-off one goes back off, one on hold back on hold with its term; any other is left as the reset made it.
     */
    public function testAResetPutsBackTheStatusMarzbansResetChanged(): void
    {
        $plan = ['data_limit' => 1, 'expire' => 2, 'add_remaining_traffic' => false, 'fire_on_either' => true];
        foreach ([
            'switched off' => [self::user(['status' => 'disabled', 'next_plan' => $plan]), ['status' => 'disabled']],
            'on hold' => [self::user(['status' => 'on_hold']), ['status' => 'on_hold', 'on_hold_expire_duration' => self::MONTH]],
            'switched off before its first connection' => [self::user(['status' => 'disabled']), ['status' => 'disabled']],
        ] as $case => [$before, $back]) {
            $provider = $this->provider([
                self::json(200, $before),
                self::json(200, self::user(['status' => 'active', 'used_traffic' => 0, 'next_plan' => null])),
                self::json(200, self::user($back)),
            ]);

            $provider->resetClientTraffic('amir_1');

            self::assertSame(['GET /api/user/amir_1', 'POST /api/user/amir_1/reset', 'PUT /api/user/amir_1'], $this->sent(), $case);
            self::assertSame('', (string) $this->call(1)->getBody(), "{$case}: the reset takes no body");
            self::assertSame($back, $this->body(2), "{$case}: the reset drops the next plan, as Marzban means it to");
        }

        foreach ([
            'active' => ['status' => 'active', 'on_hold_expire_duration' => null, 'expire' => 1893456000],
            'limited: the reset is what makes it active again' => ['status' => 'limited', 'on_hold_expire_duration' => null, 'expire' => 1893456000],
            'expired' => ['status' => 'expired', 'on_hold_expire_duration' => null, 'expire' => 1790812800],
        ] as $case => $status) {
            $provider = $this->provider([self::json(200, self::user($status)), self::json(200, self::user(['status' => $status['status'] === 'expired' ? 'expired' : 'active']))]);

            $provider->resetClientTraffic('amir_1');

            self::assertSame(['GET /api/user/amir_1', 'POST /api/user/amir_1/reset'], $this->sent(), $case);
        }

        // A panel whose reset leaves the status alone needs nothing put back.
        $provider = $this->provider([self::json(200, self::user(['status' => 'disabled'])), self::json(200, self::user(['status' => 'disabled']))]);
        $provider->resetClientTraffic('amir_1');
        self::assertCount(2, $this->sent());
    }

    public function testRotatingRevokesTheSubscriptionAndAnswersTheNewLink(): void
    {
        $provider = $this->provider([self::json(200, self::user(['subscription_url' => '/sub/bmV3LXRva2VuLXNpZ25lZDI']))]);

        $client = $provider->rotateClientCredentials('amir_1');

        self::assertSame(['POST /api/user/amir_1/revoke_sub'], $this->sent());
        self::assertSame('', (string) $this->call(0)->getBody(), 'no body');
        self::assertSame('https://mz.example:8000/sub/bmV3LXRva2VuLXNpZ25lZDI', $client->subscriptionUrl);
    }

    public function testTheServersPrefixReplacesWhateverComesBeforeTheToken(): void
    {
        $provider = $this->provider([self::json(200, self::inbounds()), self::json(200, self::user(['subscription_url' => 'http://10.0.0.5:8000/sub/dGVzdC10b2tlbi1zaWduZWQx']))], ['subscription_url' => 'https://sub.example.com/s']);

        self::assertSame('https://sub.example.com/s/dGVzdC10b2tlbi1zaWduZWQx', $provider->createClient(['VLESS TCP REALITY'], new ClientSpec('amir_1'))->subscriptionUrl);
    }

    public function testThePanelAlwaysServesSubscriptionsWithoutBeingAsked(): void
    {
        self::assertTrue($this->provider([])->servesSubscriptions());
        self::assertSame([], $this->panel->calls());
    }

    public function testTheInboundsOfItsXrayConfigAreTheInboundsAPlanSells(): void
    {
        $provider = $this->provider([self::json(200, self::inbounds() + [
            'shadowsocks' => [['tag' => 'Shadowsocks TCP', 'protocol' => 'shadowsocks', 'network' => 'tcp', 'tls' => 'none', 'port' => '1080']],
            'trojan' => [['tag' => 'Trojan Fallback', 'protocol' => 'trojan', 'network' => 'tcp', 'tls' => 'tls', 'port' => '@trojan-fallback'], 'junk'],
        ])]);

        $inbounds = $provider->listInbounds();

        self::assertSame(['GET /api/inbounds'], $this->sent());
        self::assertSame(['VLESS TCP REALITY', 'VMess WS', 'Shadowsocks TCP', 'Trojan Fallback'], array_map(static fn($inbound): string => $inbound->key, $inbounds));
        [$vless, $vmess, $shadowsocks, $fallback] = $inbounds;
        self::assertSame(['VLESS TCP REALITY', 'VLESS TCP REALITY', 'vless', 443, 'tcp', 'reality', true, 0], [$vless->tag, $vless->remark, $vless->protocol, $vless->port, $vless->network, $vless->security, $vless->enabled, $vless->clientCount]);
        self::assertSame(['vmess', 8080, 'ws', 'none'], [$vmess->protocol, $vmess->port, $vmess->network, $vmess->security]);
        self::assertSame(1080, $shadowsocks->port, 'a port sent as text');
        self::assertNull($fallback->port, 'a fallback\'s socket is no port');
    }

    public function testStatusIsTheHostsVitalsAndItsXrayCore(): void
    {
        $provider = $this->provider([
            self::json(200, [
                'version' => '0.8.4', 'mem_total' => 4096, 'mem_used' => 1024, 'cpu_cores' => 2, 'cpu_usage' => 12.5, 'total_user' => 3, 'online_users' => 1,
                'users_active' => 2, 'users_on_hold' => 1, 'users_disabled' => 0, 'users_expired' => 0, 'users_limited' => 0,
                'incoming_bandwidth' => 0, 'outgoing_bandwidth' => 0, 'incoming_bandwidth_speed' => 0, 'outgoing_bandwidth_speed' => 0,
            ]),
            self::json(200, ['version' => '25.10.31', 'started' => true, 'logs_websocket' => '/api/core/logs']),
            self::json(200, ['version' => '0.8.4', 'mem_total' => 4096, 'mem_used' => 1024, 'cpu_cores' => 2, 'cpu_usage' => 3]),
            self::json(200, ['version' => '25.10.31', 'started' => false, 'logs_websocket' => '/api/core/logs']),
        ]);

        $status = $provider->status();

        self::assertSame(['GET /api/system', 'GET /api/core'], $this->sent());
        self::assertSame([12.5, 1024, 4096, 0, 0, 0], [$status->cpuPercent, $status->memoryUsedBytes, $status->memoryTotalBytes, $status->diskUsedBytes, $status->diskTotalBytes, $status->uptimeSeconds], 'no disk or uptime on Marzban');
        self::assertSame([CoreState::Running, 'Xray', '25.10.31'], [$status->coreState, $status->coreName, $status->coreVersion]);
        self::assertSame(CoreState::Stopped, $provider->status()->coreState);
    }

    public function testTheConnectionTestReadsOneUser(): void
    {
        $provider = $this->provider([self::json(200, ['users' => [], 'total' => 0])]);

        $provider->testConnection();

        self::assertSame(['POST /api/admin/token', 'GET /api/users'], $this->panel->calls());
        self::assertSame('limit=1', $this->call(0)->getUri()->getQuery());
    }

    /**
     * A Marzban server signed in to with an admin's username and password, the panel queued with the sign-in's token
     * and then `$responses`.
     *
     * @param list<Response> $responses
     * @param array<string, mixed> $meta
     */
    private function provider(array $responses, array $meta = []): ProviderInterface
    {
        $this->app(); // registers the encrypter the Server casts need

        $this->panel = new FakePanel();
        $this->panel->raw(self::json(200, ['access_token' => 'jwt-1', 'token_type' => 'bearer']), ...$responses);

        $server = new Server([
            'name' => 'MZ',
            'driver' => 'marzban',
            'base_url' => 'https://mz.example:8000',
            'username' => 'admin',
            'password' => 'pw',
            'meta' => $meta,
        ]);

        return $this->service(MarzbanDriver::class)->connect($server, new PanelHttp($this->panel->client(), new NullLogger()));
    }

    /** @return list<string> The calls after the sign-in, as "METHOD /path". */
    private function sent(): array
    {
        $calls = $this->panel->calls();
        self::assertSame('POST /api/admin/token', $calls[0] ?? null, 'signed in first');

        return array_slice($calls, 1);
    }

    /** The n-th request after the sign-in. */
    private function call(int $index): RequestInterface
    {
        return $this->panel->request($index + 1);
    }

    /**
     * The body of the n-th request after the sign-in.
     *
     * @return array<string, mixed>
     */
    private function body(int $index): array
    {
        return $this->panel->params($index + 1);
    }

    /**
     * Two inbounds as GET /api/inbounds answers them, grouped by protocol.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function inbounds(): array
    {
        return [
            'vless' => [['tag' => 'VLESS TCP REALITY', 'protocol' => 'vless', 'network' => 'tcp', 'tls' => 'reality', 'port' => 443]],
            'vmess' => [['tag' => 'VMess WS', 'protocol' => 'vmess', 'network' => 'ws', 'tls' => 'none', 'port' => 8080]],
        ];
    }

    /**
     * A user as Marzban answers one: on hold for a month, never connected, its link a path, owned by the sudo admin (an
     * ownerless one's `admin` is null).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function user(array $overrides = []): array
    {
        return $overrides + [
            'proxies' => ['vless' => ['id' => 'b1c0e3f4-0000-4000-8000-000000000000', 'flow' => '']],
            'expire' => null,
            'data_limit' => 30 * 1024 ** 3,
            'data_limit_reset_strategy' => 'no_reset',
            'inbounds' => ['vless' => ['VLESS TCP REALITY']],
            'note' => '42 | amir',
            'sub_updated_at' => null,
            'sub_last_user_agent' => null,
            'online_at' => null,
            'on_hold_expire_duration' => self::MONTH,
            'on_hold_timeout' => null,
            'auto_delete_in_days' => null,
            'next_plan' => null,
            'username' => 'amir_1',
            'status' => 'on_hold',
            'used_traffic' => 0,
            'lifetime_used_traffic' => 0,
            'created_at' => '2026-10-05T10:00:00',
            'links' => ['vless://b1c0e3f4-0000-4000-8000-000000000000@mz.example:443?security=reality#amir_1'],
            'subscription_url' => '/sub/dGVzdC10b2tlbi1zaWduZWQx',
            'excluded_inbounds' => ['vless' => []],
            'admin' => ['username' => 'admin', 'is_sudo' => true, 'telegram_id' => null, 'discord_webhook' => null, 'users_usage' => null],
        ];
    }

    /** The admin signed in with, as GET /api/admin answers it. */
    private static function admin(bool $sudo): Response
    {
        return self::json(200, ['username' => 'admin', 'is_sudo' => $sudo, 'telegram_id' => null, 'discord_webhook' => null, 'users_usage' => 0]);
    }

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    /** Marzban's own 500: no JSON, the framework's plain text (Starlette's ServerErrorMiddleware). */
    private static function plainError(): Response
    {
        return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'Internal Server Error');
    }

    private static function link(string $text): ClientLink
    {
        return ClientLink::find($text) ?? self::fail("No link in {$text}.");
    }
}
