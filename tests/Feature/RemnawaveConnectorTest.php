<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Users\Services\WalletService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * A Remnawave panel through the generic code, against a fake of its API shaped as its NestJS backend answers: added and
 * checked on the servers screen with an API token — and an auth portal's key —, its internal squads stored as the
 * inbounds, sold through a plan on two squads with the term started at the sale, synced, and its refusals read for what
 * they are — nothing outside the connector knows which panel it is.
 */
final class RemnawaveConnectorTest extends HttpTestCase
{
    private const TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1dWlkIjoiNWIwYzdlMmEtOTFkNC00YzNmLThlNmEtMmY0YjlkMWM3YTMwIiwidXNlcm5hbWUiOm51bGwsInJvbGUiOiJBUEkiLCJpYXQiOjE3NjAwMDAwMDAsImV4cCI6MTc5MTUzNjAwMH0.a0AfkgXIXEKU9nW9aNb6MgPc0Eo7xjqCdzBkUlGGLhI';
    private const GATE = 'Basic YW1vYm90OnRlc3QtZ2F0ZQ==';
    private const DE = 'a1b2c3d4-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
    private const NL = 'b2c3d4e5-6f7a-4b8c-9dae-1f2a3b4c5d6e';
    private const EMPTY = 'c3d4e5f6-7a8b-4c9d-aeb0-2a3b4c5d6e7f';
    private const SHORT = 'Xk3pQ9vLm2Rt7Wz5';
    private const GIB = 1024 ** 3;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(new Carbon('2026-11-15 10:00:00', 'UTC'));
        $this->loginAsAdmin();
    }

    public function testAddingAServerChecksItsTokenAndStoresItsSquadsAsTheInbounds(): void
    {
        $this->panelHttp()->raw(
            self::answer(['users' => [], 'total' => 0]), // the connection test
            self::answer(['cpu' => ['cores' => 4], 'memory' => ['total' => 8 * self::GIB, 'free' => 5 * self::GIB, 'used' => 3 * self::GIB], 'uptime' => 3600, 'timestamp' => 1794736800000, 'users' => ['statusCounts' => ['ACTIVE' => 0, 'DISABLED' => 0, 'LIMITED' => 0, 'EXPIRED' => 0], 'totalUsers' => 0], 'onlineStats' => ['lastDay' => 0, 'lastWeek' => 0, 'neverOnline' => 0, 'onlineNow' => 0], 'nodes' => ['totalOnline' => 2, 'totalBytesLifetime' => '0']]),
            self::answer(['total' => 3, 'internalSquads' => [self::squad(self::DE, 'Germany', 2, 120), self::squad(self::EMPTY, 'Empty', 0, 0), self::squad(self::NL, 'Netherlands', 1, 7)]]),
        );

        $response = $this->postJson('/api/admin/servers', self::form(['base_url' => 'https://rw.example/', 'gate_key' => self::GATE]));
        $data = $this->decode($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Remnawave', $data['server']['driver_label']);
        self::assertSame(
            ['auth_mode' => 'token', 'api_token' => true, 'gate_key' => true, 'verify_tls' => true, 'timeout' => 30, 'subscription_url' => '', 'proxy_enabled' => false, 'proxy_host' => '', 'proxy_port' => 1080, 'proxy_username' => '', 'proxy_password' => false],
            array_map(static fn(mixed $value): mixed => is_array($value) ? $value['set'] : $value, array_diff_key($data['server']['form'], array_flip(['name', 'emoji_id', 'base_url', 'capacity', 'notes', 'is_active', 'button_style', 'button_row']))),
            'its connection as kept: its token and gate key, never shown, and no username or password',
        );
        self::assertSame('••••••••', $data['server']['form']['gate_key']['hint'], 'none of the gate key shown');
        self::assertTrue($data['probe']['ok']);
        self::assertSame(['unknown', null, 3 * self::GIB, 8 * self::GIB, 0, 3600], [$data['probe']['status']['core_state'], $data['probe']['status']['cpu_percent'], $data['probe']['status']['memory_used'], $data['probe']['status']['memory_total'], $data['probe']['status']['disk_total'], $data['probe']['status']['uptime_seconds']], 'no CPU load nor disk reported: none shown');
        self::assertTrue($data['server']['serves_subscriptions'], 'Remnawave always serves its links');
        self::assertSame(['GET /api/users', 'GET /api/system/stats', 'GET /api/internal-squads'], $this->panelHttp()->calls());
        foreach ($this->panelHttp()->history as $entry) {
            self::assertSame('Bearer ' . self::TOKEN, $entry['request']->getHeaderLine('Authorization'));
            self::assertSame(self::GATE, $entry['request']->getHeaderLine('X-Api-Key'), 'through the portal in front');
            self::assertFalse($entry['request']->hasHeader('X-Forwarded-For'), 'its proxy says so over https');
        }
        self::assertStringNotContainsString('YW1vYm90', (string) json_encode($data), 'the gate key goes back to no screen');

        $server = Server::query()->findOrFail($data['server']['id']);
        self::assertSame('https://rw.example', $server->base_url);
        self::assertNotSame(self::TOKEN, $server->getRawOriginal('api_token'), 'stored encrypted');
        self::assertSame(self::GATE, $server->password, 'the gate key in the password column a token-only connector has no other use for');
        self::assertNotSame(self::GATE, $server->getRawOriginal('password'), 'stored encrypted');
        $inbounds = $server->inbounds()->oldest('id')->get();
        self::assertSame([self::DE, self::EMPTY, self::NL], $inbounds->pluck('remote_key')->all(), 'its squads, by uuid');
        self::assertSame(['Germany', 'Empty', 'Netherlands'], $inbounds->pluck('remark')->all());
        self::assertSame([true, false, true], $inbounds->pluck('enabled')->map(static fn(mixed $on): bool => (bool) $on)->all(), 'a squad without an inbound gives a user nothing');
    }

    /**
     * Remnawave drops a request that came through no HTTPS proxy: on its own port on the shop's private network the shop
     * adds the headers — never across the internet —; dropped, the owner reads why.
     */
    public function testThePanelsOwnPortNearbyIsReachedWithAProxysHeadersAndADroppedRequestIsExplained(): void
    {
        $this->panelHttp()->refuse(new ConnectException('cURL error 52: Empty reply from server (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://rw.example/api/users?start=0&size=1', new Request('GET', 'x')));

        $probe = $this->decode($this->postJson('/api/admin/servers/test', self::form()))['probe'];

        self::assertFalse($probe['ok']);
        self::assertStringContainsString('ریورس‌پراکسی', $probe['error'], 'how Remnawave is reached');
        self::assertStringContainsString('Empty reply from server', (string) $probe['error_detail']);

        foreach (['http://10.0.0.5:3000' => ['127.0.0.1', 'https'], 'http://remnawave:3000' => ['127.0.0.1', 'https'], 'http://203.0.113.7:3000' => ['', '']] as $address => $forwarded) {
            $this->panelHttp()->reset();
            $this->panelHttp()->raw(self::answer(['users' => [], 'total' => 0]), self::answer([]), self::answer(['total' => 0, 'internalSquads' => []]));
            $probe = $this->decode($this->postJson('/api/admin/servers/test', self::form(['base_url' => $address])))['probe'];

            self::assertTrue($probe['ok'], $address);
            self::assertCount(3, $this->panelHttp()->history);
            foreach ($this->panelHttp()->history as $entry) {
                self::assertSame($forwarded, [$entry['request']->getHeaderLine('X-Forwarded-For'), $entry['request']->getHeaderLine('X-Forwarded-Proto')], $address);
                self::assertFalse($entry['request']->hasHeader('X-Api-Key'), 'no gate, no key');
            }
        }
    }

    public function testARefusedTokenIsExplainedInPersianWithWhereToMakeAnother(): void
    {
        $this->panelHttp()->raw(new Response(401, ['Content-Type' => 'application/json; charset=utf-8'], '{"message":"Unauthorized","statusCode":401}'));

        $probe = $this->decode($this->postJson('/api/admin/servers/test', self::form()))['probe'];

        self::assertFalse($probe['ok']);
        self::assertStringContainsString("\u{2068}API Tokens\u{2069}", $probe['error']);
        self::assertSame('Unauthorized', $probe['error_detail']);
        self::assertStringNotContainsString('eyJ1dWlk', (string) json_encode($probe));
    }

    public function testATokenThatIsNotWholeIsRefusedBeforeAnyCall(): void
    {
        $this->panelHttp(); // the fake in place first, so a call would be seen
        $response = $this->postJson('/api/admin/servers/test', self::form(['api_token' => substr(self::TOKEN, 0, 60)]));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('api_token', $this->decode($response)['errors']);
        self::assertSame([], $this->panelHttp()->calls());
    }

    public function testASaleMakesAUserInThePlansSquadsWhoseDeadlineTheShopMirrorsAndASyncFollowsThePanel(): void
    {
        $server = $this->remnawaveServer();
        $germany = $this->inbound($server, self::DE, ['tag' => 'VLESS_DE', 'remark' => 'Germany', 'protocol' => null, 'port' => null]);
        $netherlands = $this->inbound($server, self::NL, ['tag' => 'VLESS_NL', 'remark' => 'Netherlands', 'protocol' => null, 'port' => null]);
        $plan = $this->plan(['traffic_gb' => 30, 'duration_hours' => 720]);
        $this->planEntry($plan, $server, [$germany->id, $netherlands->id]);
        $customer = $this->customer(['username' => 'amir', 'telegram_id' => 4242]);
        $this->panelHttp()->raw(self::answer(self::user(['activeInternalSquads' => [['uuid' => self::DE, 'name' => 'Germany'], ['uuid' => self::NL, 'name' => 'Netherlands']]]), 201));

        $order = $this->buy($customer, $plan, $server);

        self::assertSame(['POST /api/users'], $this->panelHttp()->calls(), 'Remnawave answers the user it made');
        $sent = $this->panelHttp()->params(0);
        ksort($sent);
        self::assertSame([
            'activeInternalSquads' => [self::DE, self::NL],
            'description' => '4242 | amir',
            'expireAt' => '2026-12-15T10:00:00.000Z',
            'hwidDeviceLimit' => 1,
            'status' => 'ACTIVE',
            'telegramId' => 4242,
            'trafficLimitBytes' => 30 * self::GIB,
            'trafficLimitStrategy' => 'NO_RESET',
            'username' => 'amir_1',
        ], $sent, 'in both squads its plan picks, its month started at the sale, the plan\'s one device as its HWID limit');
        self::assertSame('Bearer ' . self::TOKEN, $this->panelHttp()->request(0)->getHeaderLine('Authorization'));

        $subscription = Subscription::query()->findOrFail($order->refresh()->subscription_id);
        self::assertSame('amir_1', $subscription->remote_name);
        self::assertSame('https://sub.example.com/' . self::SHORT, $subscription->subscription_url);
        self::assertSame('2026-12-15 10:00:00', $subscription->expires_at?->format('Y-m-d H:i:s'), 'the deadline the panel has: the clock started at the sale');

        // The customer used a GiB; the owner gave a week more on the panel by hand.
        $this->panelHttp()->raw(self::answer(['users' => [self::user(['expireAt' => '2026-12-22T10:00:00.000Z', 'userTraffic' => ['usedTrafficBytes' => self::GIB, 'lifetimeUsedTrafficBytes' => self::GIB, 'onlineAt' => '2026-11-15T09:59:50.000Z', 'firstConnectedAt' => '2026-11-15T09:30:00.000Z', 'lastConnectedNodeUuid' => null]])], 'total' => 1]));
        $this->panelHttp()->reset();
        $this->service(ProvisioningService::class)->syncServer($server->refresh());

        self::assertSame(['GET /api/users'], $this->panelHttp()->calls(), 'every user listed at once');
        self::assertSame('start=0&size=1000', $this->panelHttp()->request(0)->getUri()->getQuery());
        $subscription->refresh();
        self::assertSame('2026-12-22 10:00:00', $subscription->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame(self::GIB, $subscription->usedBytes());
        self::assertSame(1, (int) $subscription->ip_limit, 'Remnawave says no device limit: the shop\'s stays');
    }

    /** A renewal of a service the panel has turned EXPIRED sends it back on with its new term (a PATCH alone turns no expired user on with a status sent). */
    public function testARenewalOfAnExpiredServiceRunsItAgain(): void
    {
        $server = $this->remnawaveServer();
        $germany = $this->inbound($server, self::DE, ['tag' => 'VLESS_DE', 'remark' => 'Germany', 'protocol' => null, 'port' => null]);
        $plan = $this->plan(['traffic_gb' => 30, 'duration_hours' => 720]);
        $this->planEntry($plan, $server, [$germany->id]);
        $this->panelHttp()->raw(self::answer(self::user(), 201));
        $order = $this->buy($this->customer(['username' => 'amir', 'telegram_id' => 4242]), $plan, $server);
        $subscription = Subscription::query()->findOrFail($order->refresh()->subscription_id);

        // A month and a day later: out of time on the panel.
        Carbon::setTestNow(new Carbon('2026-12-16 10:00:00', 'UTC'));
        $expired = self::user(['status' => 'EXPIRED', 'userTraffic' => ['usedTrafficBytes' => 5 * self::GIB, 'lifetimeUsedTrafficBytes' => 5 * self::GIB, 'onlineAt' => null, 'firstConnectedAt' => null, 'lastConnectedNodeUuid' => null]]);
        $reset = ['userTraffic' => ['usedTrafficBytes' => 0, 'lifetimeUsedTrafficBytes' => 5 * self::GIB, 'onlineAt' => null, 'firstConnectedAt' => null, 'lastConnectedNodeUuid' => null]] + $expired;
        $this->panelHttp()->raw(
            self::answer($expired), // the service as the panel has it
            self::answer($expired), // read for its traffic reset
            self::answer($reset), // reset: usage 0, still out of time
            self::answer($reset), // read for its new term
            self::answer(self::user(['expireAt' => '2027-01-15T10:00:00.000Z'])),
        );
        $this->panelHttp()->reset();

        $this->renew($subscription);

        self::assertSame(['GET /api/users/by-username/amir_1', 'GET /api/users/by-username/amir_1', 'POST /api/users/7/actions/reset-traffic', 'GET /api/users/by-username/amir_1', 'PATCH /api/users'], $this->panelHttp()->calls());
        $sent = $this->panelHttp()->params(4);
        $switch = array_intersect_key($sent, ['id' => true, 'status' => true, 'trafficLimitStrategy' => true, 'telegramId' => true]);
        ksort($switch);
        self::assertSame(['id' => 7, 'status' => 'ACTIVE', 'telegramId' => 4242, 'trafficLimitStrategy' => 'NO_RESET'], $switch, 'on again, by its id, the quota once a term');
        self::assertGreaterThan(now()->getTimestamp() + 60, strtotime((string) $sent['expireAt']), 'a deadline the panel takes');
        self::assertSame('2027-01-15 10:00:00', $subscription->expires_at?->format('Y-m-d H:i:s'), 'the deadline the panel answered');
    }

    /** Remnawave refuses a name taken with 400 (A019), not 409: the shop asks whether the panel has a client by it, and takes the next. */
    public function testANameTakenOnThePanelGivesWayToTheNext(): void
    {
        $server = $this->remnawaveServer();
        $germany = $this->inbound($server, self::DE, ['tag' => 'VLESS_DE', 'remark' => 'Germany', 'protocol' => null, 'port' => null]);
        $plan = $this->plan(['traffic_gb' => 30, 'duration_hours' => 720]);
        $this->planEntry($plan, $server, [$germany->id]);
        $this->panelHttp()->raw(
            self::reply(400, ['timestamp' => '2026-11-15T10:00:00.000Z', 'path' => '/api/users', 'message' => 'User username already exists', 'errorCode' => 'A019']),
            self::answer(self::user(['id' => 3, 'telegramId' => null, 'description' => 'made on the panel by hand'])),
            self::answer(self::user(['id' => 8, 'username' => 'amir_2']), 201),
        );

        $order = $this->buy($this->customer(['username' => 'amir', 'telegram_id' => 4242]), $plan, $server);

        self::assertSame(['POST /api/users', 'GET /api/users/by-username/amir_1', 'POST /api/users'], $this->panelHttp()->calls());
        self::assertSame('amir_2', $this->panelHttp()->params(2)['username']);
        self::assertSame('amir_2', Subscription::query()->findOrFail($order->refresh()->subscription_id)->remote_name);
    }

    /**
     * A 403 is the token's — a scope it lacks, or deleted —, never "another admin's user": no look for whose the name is, no
     * next name; the order fails at once with the remedy for the owner.
     */
    public function testA403OnASaleIsTheTokensFailureAndNeverATakenName(): void
    {
        $server = $this->remnawaveServer();
        $germany = $this->inbound($server, self::DE, ['tag' => 'VLESS_DE', 'remark' => 'Germany', 'protocol' => null, 'port' => null]);
        $plan = $this->plan(['traffic_gb' => 30, 'duration_hours' => 720]);
        $this->planEntry($plan, $server, [$germany->id]);
        $customer = $this->customer(['username' => 'amir', 'telegram_id' => 4242]);
        $this->service(WalletService::class)->credit($customer, $plan->price, 'test');
        $this->panelHttp()->raw(self::reply(403, ['message' => 'Forbidden resource', 'error' => 'Forbidden', 'statusCode' => 403]));

        $order = $this->service(OrderService::class)->openPurchase($customer, $plan, $server);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        self::assertSame(OrderStatus::Failed, $order->refresh()->status);
        self::assertNull($order->subscription_id);
        self::assertSame(['POST /api/users'], $this->panelHttp()->calls(), 'no look at the name, no next one');
        self::assertStringContainsString("\u{2068}users:*\u{2069}", (string) $order->diagnosis, 'the owner reads which scopes the token needs');
    }

    private function remnawaveServer(): Server
    {
        return $this->panelServer('RW', ['driver' => 'remnawave', 'base_url' => 'https://rw.example', 'api_token' => self::TOKEN, 'serves_subscriptions' => true]);
    }

    /**
     * A user as Remnawave 3.x answers it: made for a month in the German squad, its link on the panel's subscription domain.
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
            'telegramId' => 4242,
            'email' => null,
            'description' => '4242 | amir',
            'tag' => null,
            'hwidDeviceLimit' => null,
            'externalSquadUuid' => null,
            'trojanPassword' => 'Tr0janPassw0rdAbCdEf12',
            'vlessUuid' => '3c5e7f9a-1b2d-4e6f-8a0b-2c4d6e8f0a1b',
            'ssPassword' => 'Sh4d0wP4ssw0rdXyZ0123',
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

    /** @return array<string, mixed> An internal squad as GET /api/internal-squads lists it. */
    private static function squad(string $uuid, string $name, int $inbounds, int $members): array
    {
        return [
            'uuid' => $uuid,
            'viewPosition' => 1,
            'name' => $name,
            'tags' => [],
            'info' => ['membersCount' => $members, 'inboundsCount' => $inbounds],
            'inbounds' => array_map(static fn(int $i): array => ['uuid' => 'd4e5f6a7-8b9c-4dae-8f01-3b4c5d6e7f80', 'profileUuid' => 'e5f6a7b8-9c0d-4ebf-9012-4c5d6e7f8091', 'tag' => "VLESS_{$i}", 'type' => 'vless', 'network' => 'tcp', 'security' => 'reality', 'port' => 443, 'rawInbound' => null], $inbounds > 0 ? range(1, $inbounds) : []),
            'createdAt' => '2026-10-01T00:00:00.000Z',
            'updatedAt' => '2026-10-01T00:00:00.000Z',
        ];
    }

    /**
     * A Remnawave server's form, with its API token, as the screen sends it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function form(array $overrides = []): array
    {
        return $overrides + ['driver' => 'remnawave', 'name' => 'RW', 'base_url' => 'https://rw.example', 'auth_mode' => 'token', 'api_token' => self::TOKEN, 'verify_tls' => true, 'timeout' => 30, 'proxy_enabled' => false, 'is_active' => true];
    }

    /** Remnawave's answer to a call that went through: its `response` envelope. */
    private static function answer(mixed $response, int $status = 200): Response
    {
        return self::reply($status, ['response' => $response]);
    }

    private static function reply(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'], (string) json_encode($body));
    }
}
