<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Providers\Drivers\Marzban\MarzbanDriver;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelHttp;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use GuzzleHttp\Psr7\Response;
use Tests\HttpTestCase;

/**
 * A Marzban panel through the generic code, against a fake of its HTTP API: added and checked on the servers screen with
 * an admin's username and password, sold through a plan entry, and synced — nothing outside the connector knows which
 * panel it is.
 */
final class MarzbanConnectorTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testAddingAServerSignsInChecksItAndStoresTheInboundsOfItsXrayConfig(): void
    {
        $this->panelHttp()->raw(
            self::answer(['access_token' => 'jwt-1', 'token_type' => 'bearer']),
            self::answer(['users' => [], 'total' => 0]), // the connection test
            self::answer(['version' => '0.8.4', 'mem_total' => 4096, 'mem_used' => 1024, 'cpu_cores' => 2, 'cpu_usage' => 3.5]),
            self::answer(['version' => '25.10.31', 'started' => true, 'logs_websocket' => '/api/core/logs']),
            self::answer([
                'vless' => [['tag' => 'VLESS TCP REALITY', 'protocol' => 'vless', 'network' => 'tcp', 'tls' => 'reality', 'port' => 443]],
                'vmess' => [['tag' => 'VMess WS', 'protocol' => 'vmess', 'network' => 'ws', 'tls' => 'none', 'port' => 8080]],
            ]),
        );

        $response = $this->postJson('/api/admin/servers', self::form(['base_url' => 'https://mz.example:8000/']));
        $data = $this->decode($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Marzban', $data['server']['driver_label']);
        self::assertSame(
            ['auth_mode' => 'password', 'username' => 'admin', 'password' => true, 'verify_tls' => true, 'timeout' => 30, 'subscription_url' => '', 'proxy_enabled' => false, 'proxy_host' => '', 'proxy_port' => 1080, 'proxy_username' => '', 'proxy_password' => false],
            array_map(static fn(mixed $value): mixed => is_array($value) ? $value['set'] : $value, array_diff_key($data['server']['form'], array_flip(['name', 'emoji_id', 'base_url', 'capacity', 'notes', 'is_active', 'button_style', 'button_row']))),
            'its connection as kept: the admin\'s password, never shown, and no token',
        );
        self::assertTrue($data['probe']['ok']);
        self::assertSame(['running', 'Xray', '25.10.31'], [$data['probe']['status']['core_state'], $data['probe']['status']['core_name'], $data['probe']['status']['core_version']]);
        self::assertTrue($data['server']['serves_subscriptions'], 'Marzban always serves its links');
        self::assertSame(['POST /api/admin/token', 'GET /api/users', 'GET /api/system', 'GET /api/core', 'GET /api/inbounds'], $this->panelHttp()->calls(), 'signed in once for the whole probe');
        self::assertSame(['grant_type' => 'password', 'username' => 'admin', 'password' => 'S3cret pass'], $this->panelHttp()->params(0));

        $server = Server::query()->findOrFail($data['server']['id']);
        self::assertNotSame('S3cret pass', $server->getRawOriginal('password'), 'stored encrypted');
        self::assertNull($server->api_token);
        $inbounds = $server->inbounds()->oldest('id')->get();
        self::assertSame(['VLESS TCP REALITY', 'VMess WS'], $inbounds->pluck('remote_key')->all());
        self::assertSame(['vless', 'vmess'], $inbounds->pluck('protocol')->all());
        self::assertSame([443, 8080], $inbounds->pluck('port')->all());
        self::assertSame(['reality', 'none'], $inbounds->pluck('security')->all());
    }

    public function testARefusedLoginIsExplainedInPersianWithThePanelsWords(): void
    {
        $this->panelHttp()->raw(new Response(401, ['Content-Type' => 'application/json', 'WWW-Authenticate' => 'Bearer'], '{"detail":"Incorrect username or password"}'));

        $probe = $this->decode($this->postJson('/api/admin/servers/test', self::form()))['probe'];

        self::assertFalse($probe['ok']);
        self::assertStringContainsString('نام کاربری یا رمز عبور', $probe['error']);
        self::assertSame('Incorrect username or password', $probe['error_detail']);
    }

    public function testAnApiTokenIsNoWayIn(): void
    {
        $response = $this->unchecked()->postJson('/api/admin/servers/test', ['driver' => 'marzban', 'name' => 'MZ', 'base_url' => 'https://mz.example:8000', 'auth_mode' => 'token', 'verify_tls' => true, 'timeout' => 30, 'proxy_enabled' => false, 'is_active' => true]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('auth_mode', $this->decode($response)['errors']);
        self::assertSame([], $this->panelHttp()->calls());
    }

    public function testASaleMakesAUserOnHoldOnTheEntrysInboundsAndASyncLearnsTheDeadlineItsFirstConnectionSet(): void
    {
        $server = $this->panelServer('MZ', ['driver' => 'marzban', 'base_url' => 'https://mz.example:8000', 'api_token' => null, 'username' => 'admin', 'password' => 'S3cret pass', 'serves_subscriptions' => true]);
        $this->inbound($server, 'VLESS TCP REALITY', ['tag' => 'VLESS TCP REALITY', 'remark' => 'VLESS TCP REALITY', 'protocol' => 'vless', 'port' => 443]);
        $plan = $this->plan(['traffic_gb' => 30, 'duration_hours' => 720], $server);
        $customer = $this->customer(['username' => 'amir', 'telegram_id' => 4242]);
        $this->panelHttp()->raw(
            self::answer(['access_token' => 'jwt-1', 'token_type' => 'bearer']),
            self::answer(['vless' => [['tag' => 'VLESS TCP REALITY', 'protocol' => 'vless', 'network' => 'tcp', 'tls' => 'reality', 'port' => 443]]]),
            self::answer(self::user(['status' => 'on_hold', 'expire' => null, 'on_hold_expire_duration' => 30 * 86400])),
        );

        $order = $this->buy($customer, $plan, $server);

        self::assertSame(['POST /api/admin/token', 'GET /api/inbounds', 'POST /api/user'], $this->panelHttp()->calls());
        $sent = $this->panelHttp()->params(2);
        self::assertSame('amir_1', $sent['username']);
        self::assertSame(['vless' => []], $sent['proxies'], 'the panel mints the credentials');
        self::assertSame(['vless' => ['VLESS TCP REALITY']], $sent['inbounds']);
        self::assertSame('on_hold', $sent['status']);
        self::assertSame(30 * 86400, $sent['on_hold_expire_duration']);
        self::assertSame(0, $sent['expire']);
        self::assertSame(30 * 1024 ** 3, $sent['data_limit']);
        self::assertSame('4242 | amir', $sent['note']);
        self::assertSame('Bearer jwt-1', $this->panelHttp()->request(2)->getHeaderLine('Authorization'));

        $subscription = Subscription::query()->findOrFail($order->refresh()->subscription_id);
        self::assertSame('amir_1', $subscription->remote_name);
        self::assertSame('https://mz.example:8000/sub/YW1pcl8xLDE3NjAyNjI0MDA4f3Kq1ZbXy', $subscription->subscription_url);
        self::assertNull($subscription->expires_at, 'the clock waits for the first connection');
        self::assertSame(30 * 24, $subscription->duration_hours);

        // The customer connected: the panel started the month and counted some traffic.
        $this->panelHttp()->raw(
            self::answer(['username' => 'admin', 'is_sudo' => true]),
            self::answer(['users' => [self::user(['status' => 'active', 'expire' => 1793786400, 'on_hold_expire_duration' => null, 'used_traffic' => 1024 ** 3])], 'total' => 1]),
        );
        $this->panelHttp()->reset();
        $this->service(ProvisioningService::class)->syncServer($server->refresh());

        self::assertSame(['GET /api/admin', 'GET /api/users'], $this->panelHttp()->calls(), 'a sudo admin\'s panel is listed at once; the token the sale signed in for serves the sync: the panel is not signed in to again');

        // Another request builds a client of its own: the token is kept across requests, encrypted, in the shop's storage.
        $this->panelHttp()->reset();
        $this->panelHttp()->raw(self::answer(self::user(['status' => 'active', 'expire' => 1793786400, 'on_hold_expire_duration' => null])));
        $this->service(MarzbanDriver::class)->connect($server, $this->service(PanelHttp::class))->findClient('amir_1');
        self::assertSame(['GET /api/user/amir_1'], $this->panelHttp()->calls());
        self::assertSame('Bearer jwt-1', $this->panelHttp()->request(0)->getHeaderLine('Authorization'));
        $kept = glob($this->app()->container()->get('panel.tokens') . '/*') ?: [];
        self::assertCount(1, $kept);
        self::assertStringNotContainsString('jwt-1', (string) file_get_contents($kept[0]));

        $subscription->refresh();
        self::assertSame('2026-11-04 10:00:00', $subscription->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-05 10:00:00', $subscription->starts_at?->format('Y-m-d H:i:s'));
        self::assertSame(1024 ** 3, $subscription->usedBytes());
    }

    public function testANonSudoAdminsServerIsSyncedServiceByService(): void
    {
        $server = $this->panelServer('MZ', ['driver' => 'marzban', 'base_url' => 'https://mz.example:8000', 'api_token' => null, 'username' => 'reseller', 'password' => 'S3cret pass', 'serves_subscriptions' => true]);
        $this->inbound($server, 'VLESS TCP REALITY', ['tag' => 'VLESS TCP REALITY', 'remark' => 'VLESS TCP REALITY', 'protocol' => 'vless', 'port' => 443]);
        $customer = $this->customer(['username' => 'amir', 'telegram_id' => 4242]);
        $this->panelHttp()->raw(
            self::answer(['access_token' => 'jwt-1', 'token_type' => 'bearer']),
            self::answer(['vless' => [['tag' => 'VLESS TCP REALITY', 'protocol' => 'vless', 'network' => 'tcp', 'tls' => 'reality', 'port' => 443]]]),
            self::answer(self::user(['status' => 'on_hold', 'expire' => null, 'on_hold_expire_duration' => 30 * 86400])),
        );
        $order = $this->buy($customer, $this->plan(['traffic_gb' => 30, 'duration_hours' => 720], $server), $server);

        // A non-sudo admin lists only the users it made: the panel is not listed at once, its services are read one by one.
        $this->panelHttp()->raw(
            self::answer(['username' => 'reseller', 'is_sudo' => false]),
            self::answer(self::user(['status' => 'active', 'expire' => 1793786400, 'on_hold_expire_duration' => null, 'used_traffic' => 1024 ** 3])),
        );
        $this->panelHttp()->reset();
        $this->service(ProvisioningService::class)->syncServer($server->refresh());

        self::assertSame(['GET /api/admin', 'GET /api/user/amir_1'], $this->panelHttp()->calls());
        self::assertSame(1024 ** 3, Subscription::query()->findOrFail($order->refresh()->subscription_id)->usedBytes());
    }

    /**
     * A user as Marzban answers one, its link a path on the panel.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function user(array $overrides): array
    {
        return $overrides + [
            'username' => 'amir_1',
            'proxies' => ['vless' => ['id' => 'b1c0e3f4-0000-4000-8000-000000000000', 'flow' => '']],
            'inbounds' => ['vless' => ['VLESS TCP REALITY']],
            'used_traffic' => 0,
            'data_limit' => 30 * 1024 ** 3,
            'online_at' => null,
            'note' => '4242 | amir',
            'created_at' => '2026-10-05T10:00:00',
            'subscription_url' => '/sub/YW1pcl8xLDE3NjAyNjI0MDA4f3Kq1ZbXy',
        ];
    }

    /**
     * A Marzban server's form, with an admin's username and password, as the screen sends it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function form(array $overrides = []): array
    {
        return $overrides + ['driver' => 'marzban', 'name' => 'MZ', 'base_url' => 'https://mz.example:8000', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'S3cret pass', 'verify_tls' => true, 'timeout' => 30, 'proxy_enabled' => false, 'is_active' => true];
    }

    /** What the fake panel answers: JSON, as every Marzban API answer is. */
    private static function answer(mixed $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}
