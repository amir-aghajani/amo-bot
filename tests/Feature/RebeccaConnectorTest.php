<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Providers\Services\ServerService;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Users\Services\WalletService;
use GuzzleHttp\Psr7\Response;
use Tests\HttpTestCase;

/**
 * A Rebecca panel through the generic code, against a fake of its API shaped as its Go handlers answer: added and checked
 * on the servers screen with an admin's API key, its services stored as the inbounds, sold through a plan on one service,
 * and synced — nothing outside the connector knows which panel it is.
 */
final class RebeccaConnectorTest extends HttpTestCase
{
    private const KEY = 'rk_q8Xv2kL0bN7rT4yZ1aS6dF3gH9jK5mP2wE4tY6uI8o_';
    private const CREDENTIAL = 'b1c0e3f412344abc8def0123456789ab';
    private const TOKEN = 'YW1pcl8xLDE3NjAyNjI0MDA8f3Kq1ZbXy';
    private const GIB = 1024 ** 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testAddingAServerChecksItsKeyAndStoresItsServicesAsTheInbounds(): void
    {
        $this->panelHttp()->raw(
            self::answer(['users' => [], 'link_templates' => new \stdClass(), 'total' => 0, 'status_breakdown' => new \stdClass()]), // the connection test
            self::answer(['version' => '1.9.1', 'cpu_usage' => 3.5, 'memory' => ['current' => self::GIB, 'total' => 4 * self::GIB, 'percent' => 25.0], 'disk' => ['current' => 10 * self::GIB, 'total' => 40 * self::GIB, 'percent' => 25.0], 'uptime_seconds' => 3600, 'xray_running' => true, 'xray_version' => '25.10.31', 'last_xray_error' => null]),
            self::answer(['services' => [
                self::panelService(3, 'Germany', 4, 120),
                self::panelService(5, 'No hosts', 0, 0),
                self::panelService(8, 'Netherlands', 2, 7),
            ], 'total' => 3]),
        );

        $response = $this->postJson('/api/admin/servers', self::form(['base_url' => 'https://rb.example:8000/']));
        $data = $this->decode($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Rebecca', $data['server']['driver_label']);
        self::assertSame(
            ['auth_mode' => 'token', 'api_token' => true, 'verify_tls' => true, 'timeout' => 30, 'subscription_url' => '', 'proxy_enabled' => false, 'proxy_host' => '', 'proxy_port' => 1080, 'proxy_username' => '', 'proxy_password' => false],
            array_map(static fn(mixed $value): mixed => is_array($value) ? $value['set'] : $value, array_diff_key($data['server']['form'], array_flip(['name', 'emoji_id', 'base_url', 'capacity', 'notes', 'is_active', 'button_style', 'button_row']))),
            'its connection as kept: its key, never shown, and no username or password',
        );
        self::assertTrue($data['probe']['ok']);
        self::assertSame(['running', 'Xray', '25.10.31'], [$data['probe']['status']['core_state'], $data['probe']['status']['core_name'], $data['probe']['status']['core_version']]);
        self::assertTrue($data['server']['serves_subscriptions'], 'Rebecca always serves its links');
        self::assertSame(['GET /api/users', 'GET /api/system', 'GET /api/v2/services'], $this->panelHttp()->calls());
        foreach ($this->panelHttp()->history as $entry) {
            self::assertSame('Bearer ' . self::KEY, $entry['request']->getHeaderLine('Authorization'));
        }
        self::assertSame('limit=null', $this->panelHttp()->request(2)->getUri()->getQuery());

        $server = Server::query()->findOrFail($data['server']['id']);
        self::assertNotSame(self::KEY, $server->getRawOriginal('api_token'), 'stored encrypted');
        self::assertNull($server->password);
        $inbounds = $server->inbounds()->oldest('id')->get();
        self::assertSame(['3', '5', '8'], $inbounds->pluck('remote_key')->all(), 'its services, by id');
        self::assertSame(['Germany', 'No hosts', 'Netherlands'], $inbounds->pluck('remark')->all());
        self::assertSame([true, false, true], $inbounds->pluck('enabled')->map(static fn(mixed $on): bool => (bool) $on)->all(), 'one with no active host takes no user');
    }

    public function testARefusedKeyIsExplainedInPersianWithWhereToMakeAnother(): void
    {
        $this->panelHttp()->raw(new Response(401, ['Content-Type' => 'application/json', 'WWW-Authenticate' => 'Bearer'], "{\"detail\":\"Could not validate credentials\"}\n"));

        $probe = $this->decode($this->postJson('/api/admin/servers/test', self::form()))['probe'];

        self::assertFalse($probe['ok']);
        self::assertStringContainsString('«حساب من ← کلیدهای API»', $probe['error']);
        self::assertSame('Could not validate credentials', $probe['error_detail']);
        self::assertStringNotContainsString(self::KEY, (string) json_encode($probe));
    }

    public function testAKeyThatIsNotWholeIsRefusedBeforeAnyCall(): void
    {
        $this->panelHttp(); // the fake in place first, so a call would be seen
        $response = $this->postJson('/api/admin/servers/test', self::form(['api_token' => 'rk_q8Xv2kL0bN7rT4yZ']));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('api_token', $this->decode($response)['errors']);
        self::assertSame([], $this->panelHttp()->calls());
    }

    public function testASaleMakesAUserOnHoldInThePlansServiceAndASyncLearnsTheDeadlineItsFirstConnectionSet(): void
    {
        $server = $this->rebeccaServer();
        $germany = $this->inbound($server, '3', ['tag' => 'Germany', 'remark' => 'Germany', 'protocol' => null, 'port' => null]);
        $this->inbound($server, '8', ['tag' => 'Netherlands', 'remark' => 'Netherlands', 'protocol' => null, 'port' => null]);
        $plan = $this->plan(['traffic_gb' => 30, 'duration_hours' => 720]);
        $this->planEntry($plan, $server, [$germany->id]);
        $customer = $this->customer(['username' => 'amir', 'telegram_id' => 4242]);
        $this->panelHttp()->raw(self::answer(self::user(), 201));

        $order = $this->buy($customer, $plan, $server);

        self::assertSame(['POST /api/user'], $this->panelHttp()->calls(), 'Rebecca answers the user it made');
        $sent = $this->panelHttp()->params(0);
        ksort($sent);
        self::assertSame([
            'data_limit' => 30 * self::GIB,
            'data_limit_reset_strategy' => 'no_reset',
            'ip_limit' => 1,
            'note' => '4242 | amir',
            'on_hold_expire_duration' => 30 * 86400,
            'service_id' => 3,
            'status' => 'on_hold',
            'username' => 'amir_1',
        ], $sent, 'in the one service its plan picks, its month waiting for the first connection');
        self::assertSame('Bearer ' . self::KEY, $this->panelHttp()->request(0)->getHeaderLine('Authorization'));

        $subscription = Subscription::query()->findOrFail($order->refresh()->subscription_id);
        self::assertSame('amir_1', $subscription->remote_name);
        self::assertSame('https://rb.example:8000/sub/' . self::CREDENTIAL, $subscription->subscription_url);
        self::assertNull($subscription->expires_at, 'the clock waits for the first connection');
        self::assertSame(30 * 24, $subscription->duration_hours);

        // The customer connected: Rebecca started the month (expire = then + duration, duration cleared) and counted traffic.
        $this->panelHttp()->raw(
            self::answer(['id' => 1, 'username' => 'admin', 'role' => 'full_access']),
            self::answer(['users' => [self::listed(['status' => 'active', 'expire' => 1793786400, 'used_traffic' => self::GIB, 'online_at' => '2026-11-05T09:00:00Z'])], 'link_templates' => new \stdClass(), 'total' => 1, 'status_breakdown' => ['active' => 1]]),
        );
        $this->panelHttp()->reset();
        $this->service(ProvisioningService::class)->syncServer($server->refresh());

        self::assertSame(['GET /api/admin', 'GET /api/users'], $this->panelHttp()->calls(), 'a full-access admin\'s panel is listed at once');
        self::assertSame('offset=0&limit=500&sort=username', $this->panelHttp()->request(1)->getUri()->getQuery());
        $subscription->refresh();
        self::assertSame('2026-11-04 10:00:00', $subscription->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-05 10:00:00', $subscription->starts_at?->format('Y-m-d H:i:s'));
        self::assertSame(self::GIB, $subscription->usedBytes());
        self::assertSame(1, (int) $subscription->ip_limit, 'the list does not say the device limit: the shop\'s stays');
    }

    public function testAnAdminWhoSeesOnlyItsOwnUsersIsSyncedUserByUser(): void
    {
        $server = $this->rebeccaServer();
        $germany = $this->inbound($server, '3', ['tag' => 'Germany', 'remark' => 'Germany', 'protocol' => null, 'port' => null]);
        $plan = $this->plan(['traffic_gb' => 30, 'duration_hours' => 720]);
        $this->planEntry($plan, $server, [$germany->id]);
        $this->panelHttp()->raw(self::answer(self::user(), 201));
        $order = $this->buy($this->customer(['username' => 'amir', 'telegram_id' => 4242]), $plan, $server);

        $this->panelHttp()->raw(
            self::answer(['id' => 2, 'username' => 'seller', 'role' => 'reseller']),
            self::answer(self::user(['status' => 'active', 'expire' => 1793786400, 'on_hold_expire_duration' => null, 'used_traffic' => self::GIB])),
        );
        $this->panelHttp()->reset();
        $this->service(ProvisioningService::class)->syncServer($server->refresh());

        self::assertSame(['GET /api/admin', 'GET /api/user/amir_1'], $this->panelHttp()->calls());
        self::assertSame(self::GIB, Subscription::query()->findOrFail($order->refresh()->subscription_id)->usedBytes());
    }

    /**
     * A plan sold on the whole server with two services would put one user in both: refused before anything is written,
     * the order failed with the remedy for the owner. (The shop asks whether a refused name is taken — a read — first.)
     */
    public function testASaleInSeveralServicesOfOneServerIsRefusedBeforeAnythingIsWritten(): void
    {
        $server = $this->rebeccaServer();
        $this->inbound($server, '3', ['tag' => 'Germany', 'remark' => 'Germany', 'protocol' => null, 'port' => null]);
        $this->inbound($server, '8', ['tag' => 'Netherlands', 'remark' => 'Netherlands', 'protocol' => null, 'port' => null]);
        $plan = $this->plan(['traffic_gb' => 30, 'duration_hours' => 720], $server);
        $customer = $this->customer(['username' => 'amir', 'telegram_id' => 4242]);
        $this->service(WalletService::class)->credit($customer, $plan->price, 'test');
        $this->panelHttp()->raw(self::answer(['detail' => 'User not found'], 404));

        $order = $this->service(OrderService::class)->openPurchase($customer, $plan, $server);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        self::assertSame(OrderStatus::Failed, $order->refresh()->status);
        self::assertNull($order->subscription_id);
        self::assertSame(['GET /api/user/amir_1'], $this->panelHttp()->calls(), 'no user made');
        self::assertSame('سرور درخواست را نپذیرفت.', $order->notes, 'what any reader of the order may read');
        self::assertStringContainsString('روی این سرور فقط یک سرویس انتخاب کنید', (string) $order->diagnosis, 'the owner reads what to change');
    }

    public function testOnlyOneOfAServersServicesIsForSaleSinceAUserLivesInOne(): void
    {
        $server = $this->rebeccaServer();
        $first = $this->inbound($server, '3');
        $second = $this->inbound($server, '4', ['is_selectable' => false]);
        $servers = $this->service(ServerService::class);

        try {
            $servers->setSelectable($second, true);
            self::fail('a second service for sale on a Rebecca server');
        } catch (ValidationException $e) {
            self::assertSame([ServerService::ONE_INBOUND], $e->errors()['is_selectable']);
        }
        self::assertFalse($second->refresh()->is_selectable);

        // The first one taken off sale: the other may be sold instead.
        $servers->setSelectable($first, false);
        $servers->setSelectable($second, true);
        self::assertSame([false, true], [$first->refresh()->is_selectable, $second->refresh()->is_selectable]);

        // Another panel sells from as many as the owner picks.
        $xui = $this->panelServer('DE-2');
        $this->inbound($xui, '1');
        $servers->setSelectable($this->inbound($xui, '2', ['is_selectable' => false]), true);
        self::assertSame(2, ServerInbound::query()->where('server_id', $xui->id)->where('is_selectable', true)->count());
    }

    private function rebeccaServer(): Server
    {
        return $this->panelServer('RB', ['driver' => 'rebecca', 'base_url' => 'https://rb.example:8000', 'api_token' => self::KEY, 'serves_subscriptions' => true]);
    }

    /**
     * A user's detail as Rebecca answers it: made on hold for a month in service 3, its link the panel's path.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function user(array $overrides = []): array
    {
        return $overrides + [
            'username' => 'amir_1',
            'credential_key' => self::CREDENTIAL,
            'status' => 'on_hold',
            'used_traffic' => 0,
            'lifetime_used_traffic' => 0,
            'created_at' => '2026-10-05T10:00:00Z',
            'expire' => null,
            'data_limit' => 30 * self::GIB,
            'data_limit_reset_strategy' => 'no_reset',
            'note' => '4242 | amir',
            'online_at' => null,
            'on_hold_expire_duration' => 30 * 86400,
            'on_hold_timeout' => null,
            'ip_limit' => 1,
            'service_id' => 3,
            'service_name' => 'Germany',
            'next_plans' => [],
            'links' => [],
            'subscription_url' => '/sub/' . self::CREDENTIAL,
            'subscription_urls' => ['key' => '/sub/' . self::CREDENTIAL, 'token' => '/sub/' . self::TOKEN],
        ];
    }

    /**
     * A user as Rebecca lists it: no device limit, no waiting term's duration.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function listed(array $overrides): array
    {
        return $overrides + [
            'id' => 7,
            'username' => 'amir_1',
            'status' => 'active',
            'used_traffic' => 0,
            'lifetime_used_traffic' => 0,
            'created_at' => '2026-10-05T10:00:00Z',
            'expire' => null,
            'data_limit' => 30 * self::GIB,
            'online_at' => null,
            'is_online' => false,
            'service_id' => 3,
            'service_name' => 'Germany',
            'links' => [],
            'subscription_url' => '/sub/' . self::CREDENTIAL,
            'subscription_urls' => ['key' => '/sub/' . self::CREDENTIAL, 'token' => '/sub/' . self::TOKEN],
        ];
    }

    /** @return array<string, mixed> A service as GET /api/v2/services lists it. */
    private static function panelService(int $id, string $name, int $hosts, int $users): array
    {
        return ['id' => $id, 'name' => $name, 'description' => null, 'usage_coefficient' => 1.0, 'used_traffic' => 0, 'lifetime_used_traffic' => 0, 'host_count' => $hosts, 'user_count' => $users, 'has_hosts' => $hosts > 0, 'broken' => $hosts === 0];
    }

    /**
     * A Rebecca server's form, with its API key, as the screen sends it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function form(array $overrides = []): array
    {
        return $overrides + ['driver' => 'rebecca', 'name' => 'RB', 'base_url' => 'https://rb.example:8000', 'auth_mode' => 'token', 'api_token' => self::KEY, 'verify_tls' => true, 'timeout' => 30, 'proxy_enabled' => false, 'is_active' => true];
    }

    /** What the fake panel answers: JSON, as every answer of Rebecca's API is. */
    private static function answer(mixed $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body) . "\n");
    }
}
