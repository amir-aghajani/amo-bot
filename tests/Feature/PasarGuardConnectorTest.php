<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use GuzzleHttp\Psr7\Response;
use Tests\HttpTestCase;

/**
 * A PasarGuard panel through the generic code, against a fake of its HTTP API: added and checked on the
 * servers screen, sold through a plan entry, and synced — nothing outside the driver knows which panel it is.
 */
final class PasarGuardConnectorTest extends HttpTestCase
{
    private const KEY = 'pg_key_6f1c2a3b-4d5e-4f60-8a1b-2c3d4e5f6a7b';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testAddingAServerChecksItAndStoresItsGroupsAsInbounds(): void
    {
        $this->panelHttp()->raw(
            self::answer(['users' => [], 'total' => 0]), // the connection test
            self::answer(['version' => '5.4.1', 'uptime_seconds' => 60, 'mem_total' => 4096, 'mem_used' => 1024, 'disk_total' => 8192, 'disk_used' => 2048, 'cpu_cores' => 2, 'cpu_usage' => 3.5]),
            self::answer(['groups' => [
                ['id' => 3, 'name' => 'VIP', 'inbound_tags' => ['VLESS TCP', 'Trojan WS'], 'is_disabled' => false, 'total_users' => 4],
                ['id' => 5, 'name' => 'Old', 'inbound_tags' => [], 'is_disabled' => true, 'total_users' => 0],
            ], 'total' => 2]),
        );

        $response = $this->postJson('/api/admin/servers', self::form(['base_url' => 'https://pg.example:8000/']));
        $data = $this->decode($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('PasarGuard', $data['server']['driver_label']);
        self::assertSame(
            ['auth_mode' => 'token', 'api_token' => true, 'username' => '', 'password' => false, 'verify_tls' => true, 'timeout' => 30, 'subscription_url' => ''],
            array_map(static fn(mixed $value): mixed => is_array($value) ? $value['set'] : $value, array_diff_key($data['server']['form'], array_flip(['name', 'base_url', 'capacity', 'notes', 'is_active']))),
            'its connection as kept: its key, never shown, and no two-factor secret of its own',
        );
        self::assertTrue($data['probe']['ok']);
        self::assertSame('unknown', $data['probe']['status']['core_state']);
        self::assertTrue($data['server']['serves_subscriptions'], 'PasarGuard always serves its links');
        self::assertSame(['GET /api/users', 'GET /api/system', 'GET /api/groups'], $this->panelHttp()->calls());
        self::assertSame(self::KEY, $this->panelHttp()->request(0)->getHeaderLine('X-Api-Key'));

        $server = Server::query()->findOrFail($data['server']['id']);
        self::assertNotSame(self::KEY, $server->getRawOriginal('api_token'), 'stored encrypted');
        $groups = $server->inbounds()->oldest('id')->get();
        self::assertSame(['3', '5'], $groups->pluck('remote_key')->all());
        self::assertSame(['VIP', 'Old'], $groups->pluck('remark')->all());
        self::assertSame([true, false], $groups->pluck('enabled')->all());
    }

    public function testARefusedKeyIsExplainedInPersianWithWhereToMakeAnother(): void
    {
        $this->panelHttp()->raw(new Response(401, ['Content-Type' => 'application/json'], '{"detail":"Could not validate credentials"}'));

        $probe = $this->decode($this->postJson('/api/admin/servers/test', self::form()))['probe'];

        self::assertFalse($probe['ok']);
        self::assertStringContainsString('توکن API', $probe['error']);
        self::assertStringContainsString('API Keys', $probe['error']);
    }

    public function testASaleMakesAUserOnHoldInTheEntrysGroupsAndASyncLearnsTheDeadlineItsFirstConnectionSet(): void
    {
        $server = $this->panelServer('PG', ['driver' => 'pasarguard', 'base_url' => 'https://pg.example:8000', 'api_token' => self::KEY, 'serves_subscriptions' => true]);
        $this->inbound($server, '3', ['remark' => 'VIP', 'protocol' => '', 'port' => 0]);
        $plan = $this->plan(['traffic_gb' => 30, 'duration_days' => 30], $server);
        $customer = $this->customer(['username' => 'amir', 'telegram_id' => 4242]);
        $this->panelHttp()->raw(self::answer(self::user(['status' => 'on_hold', 'expire' => null, 'on_hold_expire_duration' => 30 * 86400]), 201));

        $order = $this->buy($customer, $plan, $server);

        self::assertSame(['POST /api/user'], $this->panelHttp()->calls());
        $sent = $this->panelHttp()->params(0);
        self::assertSame('amir_1', $sent['username']);
        self::assertSame([3], $sent['group_ids']);
        self::assertSame('on_hold', $sent['status']);
        self::assertSame(30 * 86400, $sent['on_hold_expire_duration']);
        self::assertSame(30 * 1024 ** 3, $sent['data_limit']);
        self::assertSame('4242 | amir', $sent['note']);

        $subscription = Subscription::query()->findOrFail($order->refresh()->subscription_id);
        self::assertSame('amir_1', $subscription->remote_name);
        self::assertSame('https://pg.example:8000/sub/dG9rZW4.sig', $subscription->subscription_url);
        self::assertNull($subscription->expires_at, 'the clock waits for the first connection');
        self::assertSame(30, $subscription->duration_days);

        // The customer connected: the panel started the month and counted some traffic.
        $this->panelHttp()->raw(self::answer(['users' => [self::user(['status' => 'active', 'expire' => '2026-11-04T10:00:00Z', 'on_hold_expire_duration' => null, 'used_traffic' => 1024 ** 3])], 'total' => 1]));
        $this->service(ProvisioningService::class)->syncServer($server);

        $subscription->refresh();
        self::assertSame('2026-11-04 10:00:00', $subscription->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-05 10:00:00', $subscription->starts_at?->format('Y-m-d H:i:s'));
        self::assertSame(1024 ** 3, $subscription->usedBytes());
    }

    /**
     * A user as PasarGuard answers one, its link a path on the panel.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function user(array $overrides): array
    {
        return $overrides + [
            'id' => 7,
            'username' => 'amir_1',
            'used_traffic' => 0,
            'data_limit' => 30 * 1024 ** 3,
            'online_at' => null,
            'note' => '4242 | amir',
            'group_ids' => [3],
            'subscription_url' => '/sub/dG9rZW4.sig',
        ];
    }

    /**
     * A PasarGuard server's form, with its API key, as the screen sends it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function form(array $overrides = []): array
    {
        return $overrides + ['driver' => 'pasarguard', 'name' => 'PG', 'base_url' => 'https://pg.example:8000', 'auth_mode' => 'token', 'api_token' => self::KEY, 'verify_tls' => true, 'timeout' => 30, 'is_active' => true];
    }

    /** What the fake panel answers: JSON, as every PasarGuard API answer is. */
    private static function answer(mixed $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}
