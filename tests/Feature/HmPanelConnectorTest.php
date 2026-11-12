<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use GuzzleHttp\Psr7\Response;
use Tests\HttpTestCase;

/**
 * An HMPanel panel through the generic code, against a fake of its key API: added and checked on the servers screen,
 * sold through a plan entry, and synced — nothing outside the connector knows which panel it is.
 */
final class HmPanelConnectorTest extends HttpTestCase
{
    private const KEY = 'hmp_q8Xv2kL0bN7rT4yZ1aS6dF3gH9jK5mP2';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testAddingAServerChecksItsKeyAndStoresTheInboundsOfItsThreeXuiPanels(): void
    {
        $this->panelHttp()->raw(
            self::answer(['id' => 'k1', 'name' => 'AmoBot', 'scopes' => ['*'], 'rateLimitPerMin' => 60, 'webhookUrl' => null]), // the connection test
            self::answer(['data' => [
                ['id' => 'in1', 'tag' => 'inbound-443', 'remark' => 'DE', 'protocol' => 'vless', 'port' => 443, 'panel' => ['id' => 'p1', 'name' => 'Germany', 'panelType' => '3x-ui']],
                ['id' => 'in2', 'tag' => 'pg', 'remark' => 'PG', 'protocol' => 'vless', 'port' => 0, 'panel' => ['id' => 'p2', 'name' => 'Pasar', 'panelType' => 'pasarguard']],
                ['id' => 'in3', 'tag' => 'inbound-8443', 'remark' => 'NL', 'protocol' => 'trojan', 'port' => 8443, 'panel' => ['id' => 'p3', 'name' => 'Netherlands', 'panelType' => '3x-ui']],
            ]]),
        );

        $response = $this->postJson('/api/admin/servers', self::form(['base_url' => 'https://hm.example/']));
        $data = $this->decode($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('HMPanel (HMray)', $data['server']['driver_label']);
        self::assertSame(
            ['auth_mode' => 'token', 'api_token' => true, 'verify_tls' => true, 'timeout' => 30, 'subscription_url' => '', 'proxy_enabled' => false, 'proxy_host' => '', 'proxy_port' => 1080, 'proxy_username' => '', 'proxy_password' => false],
            array_map(static fn(mixed $value): mixed => is_array($value) ? $value['set'] : $value, array_diff_key($data['server']['form'], array_flip(['name', 'emoji_id', 'base_url', 'capacity', 'notes', 'is_active', 'button_style', 'button_row']))),
            'its connection as kept: its key, never shown, and no username or password',
        );
        self::assertTrue($data['probe']['ok']);
        self::assertNull($data['probe']['status'], 'HMPanel\'s key API has no host statistics');
        self::assertTrue($data['server']['serves_subscriptions'], 'HMPanel always serves its links');
        self::assertSame(['GET /api/v1/me', 'GET /api/v1/inbounds'], $this->panelHttp()->calls(), 'two calls of the key\'s minute');
        self::assertSame('Bearer ' . self::KEY, $this->panelHttp()->request(0)->getHeaderLine('Authorization'));

        $server = Server::query()->findOrFail($data['server']['id']);
        self::assertNotSame(self::KEY, $server->getRawOriginal('api_token'), 'stored encrypted');
        $inbounds = $server->inbounds()->oldest('id')->get();
        self::assertSame(['p1:in1', 'p3:in3'], $inbounds->pluck('remote_key')->all(), 'only its 3x-ui panels\'');
        self::assertSame(['Germany · DE', 'Netherlands · NL'], $inbounds->pluck('remark')->all());
    }

    public function testARefusedKeyIsExplainedInPersianWithWhereToMakeAnother(): void
    {
        $this->panelHttp()->raw(new Response(401, ['Content-Type' => 'application/json'], '{"statusCode":401,"message":"Invalid API key","error":"Unauthorized"}'));

        $probe = $this->decode($this->postJson('/api/admin/servers/test', self::form()))['probe'];

        self::assertFalse($probe['ok']);
        self::assertStringContainsString('توکن API', $probe['error']);
        self::assertStringContainsString('«تنظیمات ← API»', $probe['error']);
        self::assertSame('Invalid API key', $probe['error_detail']);
    }

    public function testAKeyWithoutTheScopesASaleNeedsIsRefusedByTheTestNamingThem(): void
    {
        $this->panelHttp()->raw(self::answer(['id' => 'k1', 'name' => 'AmoBot', 'scopes' => ['clients.read', 'inbounds.read'], 'rateLimitPerMin' => 60]));

        $probe = $this->decode($this->postJson('/api/admin/servers/test', self::form()))['probe'];

        self::assertFalse($probe['ok']);
        self::assertStringContainsString('دسترسی لازم را ندارد', $probe['error']);
        self::assertStringContainsString('clients.write، clients.renew', $probe['error']);
        self::assertSame('Missing scope: clients.write, clients.renew', $probe['error_detail']);
    }

    public function testASaleMakesAClientOnTheEntrysInboundsAndASyncLearnsTheDeadlineItsFirstConnectionSet(): void
    {
        $server = $this->panelServer('HM', ['driver' => 'hmpanel', 'base_url' => 'https://hm.example', 'api_token' => self::KEY, 'serves_subscriptions' => true]);
        $this->inbound($server, 'p1:in1', ['remark' => 'Germany · DE', 'protocol' => 'vless', 'port' => 443]);
        $plan = $this->plan(['traffic_gb' => 30, 'duration_hours' => 720], $server);
        $customer = $this->customer(['username' => 'amir', 'telegram_id' => 4242]);
        $this->panelHttp()->raw(
            self::answer(['id' => 'c1', 'panelId' => 'p1', 'email' => 'amir_1', 'subToken' => '9f8e7d6c5b', 'total' => '32212254720', 'expiryTime' => '-2592000000'], 201),
            self::answer(self::client([])),
        );

        $order = $this->buy($customer, $plan, $server);

        self::assertSame(['POST /api/v1/clients', 'GET /api/v1/clients/c1'], $this->panelHttp()->calls());
        self::assertSame([
            'email' => 'amir_1',
            'inboundIds' => ['in1'],
            'total' => 30 * 1024 ** 3,
            'expiryTime' => -30 * 86400 * 1000,
            'limitIp' => 1,
            'remark' => '4242 | amir',
        ], $this->panelHttp()->params(0));

        $subscription = Subscription::query()->findOrFail($order->refresh()->subscription_id);
        self::assertSame('amir_1', $subscription->remote_name);
        self::assertSame('https://hm.example/s/9f8e7d6c5b', $subscription->subscription_url);
        self::assertNull($subscription->expires_at, 'the clock waits for the first connection');
        self::assertSame(30 * 24, $subscription->duration_hours);

        // The customer connected: 3x-ui started the month, and HMPanel counted some traffic.
        $deadline = new \DateTimeImmutable('2026-11-04 10:00:00', new \DateTimeZone('UTC'));
        $this->panelHttp()->raw(self::answer(['data' => [self::client(['expiryTime' => $deadline->getTimestamp() * 1000, 'upBytes' => 1024 ** 2, 'downBytes' => 1024 ** 3])], 'page' => 1, 'limit' => 200, 'total' => 1]));
        $this->service(ProvisioningService::class)->syncServer($server);

        $subscription->refresh();
        self::assertSame('2026-11-04 10:00:00', $subscription->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-05 10:00:00', $subscription->starts_at?->format('Y-m-d H:i:s'));
        self::assertSame(1024 ** 3 + 1024 ** 2, $subscription->usedBytes());
    }

    /**
     * A client as HMPanel answers one: on its Germany panel, its term waiting a month.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function client(array $overrides): array
    {
        return $overrides + [
            'id' => 'c1',
            'email' => 'amir_1',
            'remark' => '4242 | amir',
            'subId' => 'a1b2c3d4e5f60718',
            'subToken' => '9f8e7d6c5b',
            'subscriptionUrl' => 'https://hm.example/s/9f8e7d6c5b',
            'enable' => true,
            'status' => 'active',
            'upBytes' => 0,
            'downBytes' => 0,
            'totalBytes' => 30 * 1024 ** 3,
            'expiryTime' => -30 * 86400 * 1000,
            'limitIp' => 1,
            'panel' => ['id' => 'p1', 'name' => 'Germany', 'type' => '3x-ui'],
        ];
    }

    /**
     * An HMPanel server's form, with its API key, as the screen sends it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function form(array $overrides = []): array
    {
        return $overrides + ['driver' => 'hmpanel', 'name' => 'HM', 'base_url' => 'https://hm.example', 'auth_mode' => 'token', 'api_token' => self::KEY, 'verify_tls' => true, 'timeout' => 30, 'proxy_enabled' => false, 'is_active' => true];
    }

    /** What the fake panel answers: JSON, as every answer of HMPanel's key API is. */
    private static function answer(mixed $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}
