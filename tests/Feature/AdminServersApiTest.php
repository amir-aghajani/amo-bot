<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Providers\Services\ServerReadiness;
use App\Modules\Providers\Services\ServerService;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Validation;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;
use Tests\Support\FakePanel;

/**
 * The servers API against a fake 3x-ui panel (the app's outgoing HTTP client, answered by FakePanel): the connectors and a
 * server's form on each; adding, editing and probing a server by it — each question to a good connection asked on its
 * own —, a check that keeps what it could not learn, the inbounds kept in step, why customers are not sold on a server,
 * and a delete refused while it has services.
 */
final class AdminServersApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testTheConnectorsAreDescribedWithAServersFormOnEach(): void
    {
        $data = $this->decode($this->get('/api/admin/servers/drivers'));

        self::assertSame(['3x-ui', 'pasarguard'], array_column($data['drivers'], 'key'), 'in the order they are registered');
        $driver = $data['drivers'][0];
        self::assertSame(['key' => '3x-ui', 'label' => '3X-UI'], ['key' => $driver['key'], 'label' => $driver['label']]);
        self::assertSame(['mark' => '3X', 'vendor' => 'MHSanaei', 'docs_url' => 'https://github.com/MHSanaei/3x-ui', 'inbounds' => true, 'link_rotation' => true], $driver['traits'], 'what the picker draws it by, and what its panels can do');
        self::assertNotEmpty(array_filter($driver['notes'], static fn(string $note): bool => str_contains($note, 'نسخه 3')), 'the versions it needs');
        self::assertSame(
            ['name', 'base_url', 'auth_mode', 'api_token', 'username', 'password', 'totp_secret', 'verify_tls', 'timeout', 'subscription_url', 'capacity', 'notes', 'is_active'],
            array_column($driver['fields'], 'name'),
            'the server\'s name, the connector\'s connection, the server\'s own columns',
        );
        self::assertSame(
            ['verify_tls', 'timeout', 'subscription_url', 'capacity', 'notes', 'is_active'],
            array_column(array_filter($driver['fields'], static fn(array $field): bool => $field['advanced']), 'name'),
            'under «تنظیمات پیشرفته»',
        );
    }

    public function testAServersFormReportsEveryRefusalAtOnce(): void
    {
        $response = $this->postJson('/api/admin/servers', ['name' => '', 'base_url' => 'panel.example', 'capacity' => '۲۰۰ گیگ', 'timeout' => 1, 'api_token' => ''] + self::tokenForm());
        $data = $this->decode($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertEqualsCanonicalizing(['name', 'capacity', 'base_url', 'api_token', 'timeout'], array_keys($data['errors']), 'the server\'s own columns and its connection, in one answer');
        self::assertSame(0, Server::query()->count());

        $max = (int) (new \ReflectionClassConstant(ServerService::class, 'NAME_MAX'))->getValue();
        $response = $this->postJson('/api/admin/servers', ['name' => str_repeat('س', $max + 1)] + self::tokenForm());
        self::assertSame([Validation::tooLong('نام سرور', $max)], $this->decode($response)['errors']['name']);

        $response = $this->postJson('/api/admin/servers', ['base_url' => 'https://host:2053/base/panel/', 'auth_mode' => 'password', 'username' => 'admin'] + self::tokenForm());
        $data = $this->decode($response);
        self::assertEqualsCanonicalizing(['base_url', 'password'], array_keys($data['errors']), '/panel must be stripped by the admin, and a password way in has its password');
    }

    public function testAServerIsOnAConnectorThisInstallationHasAndStaysOnIt(): void
    {
        // A connector none of the requests' shapes names: a request no panel sends.
        $response = $this->unchecked()->postJson('/api/admin/servers', ['driver' => 'marzban', 'name' => 'x', 'base_url' => 'https://x.example']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['driver'], array_keys($this->decode($response)['errors']));
        self::assertSame(['driver'], array_keys($this->decode($this->unchecked()->postJson('/api/admin/servers/test', ['driver' => 'marzban', 'name' => 'x', 'base_url' => 'https://x.example']))['errors']), 'nor is one probed');

        $server = $this->panelServer();
        $pasarguard = ['driver' => 'pasarguard', 'api_token' => 'pg_key_6f1c2a3b-4d5e-4f60-8a1b-2c3d4e5f6a7b'] + self::tokenForm();
        $response = $this->putJson("/api/admin/servers/{$server->id}", $pasarguard);
        self::assertSame(['driver'], array_keys($this->decode($response)['errors']), 'a server\'s connector does not change');
        self::assertSame(['driver'], array_keys($this->decode($this->postJson('/api/admin/servers/test', ['id' => $server->id] + $pasarguard))['errors']));
        self::assertSame('3x-ui', $server->refresh()->driver);
    }

    public function testCapacityTakesPersianDigitsAndBlankMeansUnlimited(): void
    {
        $this->panelHttp()->healthyPanel();
        $data = $this->decode($this->postJson('/api/admin/servers', self::tokenForm() + ['capacity' => '۲۰۰']));
        self::assertSame(200, $data['server']['capacity']);

        $data = $this->decode($this->putJson("/api/admin/servers/{$data['server']['id']}", self::tokenForm() + ['capacity' => '']));
        self::assertNull($data['server']['capacity']);
        self::assertSame('', $data['server']['form']['capacity'], 'no limit, as the form holds it');

        $data = $this->decode($this->putJson("/api/admin/servers/{$data['server']['id']}", self::tokenForm() + ['capacity' => 0]));
        self::assertSame(0, $data['server']['capacity'], 'zero is a full server, not "unlimited"');
    }

    public function testUnsavedCredentialsCanBeProbedAndTheProbeCarriesNoClientSecrets(): void
    {
        $this->panelHttp()->healthyPanel();

        $response = $this->postJson('/api/admin/servers/test', self::tokenForm());
        $probe = $this->decode($response)['probe'];

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($probe['ok']);
        self::assertSame('running', $probe['status']['core_state']);
        self::assertSame('Xray', $probe['status']['core_name']);
        self::assertCount(2, $probe['inbounds']);
        self::assertSame(['remote_key' => '1', 'tag' => 'in-443', 'protocol' => 'vless', 'port' => 443, 'remark' => 'VLESS', 'enabled' => true, 'network' => 'tcp', 'security' => 'reality', 'client_count' => 1], $probe['inbounds'][0]);
        self::assertStringNotContainsString('secret-uuid', (string) $response->getBody(), 'the panel\'s client settings never reach the browser');
        self::assertTrue($probe['serves_subscriptions']);
        self::assertTrue($probe['subscription_probed']);
        self::assertSame(0, Server::query()->count(), 'a probe must not persist anything');
        self::assertSame('Bearer tok-1', $this->panelHttp()->request(0)->getHeaderLine('Authorization'));
    }

    public function testCreatingAServerStoresEncryptedSecretsAndRunsAFirstCheck(): void
    {
        $this->panelHttp()->healthyPanel();

        $response = $this->postJson('/api/admin/servers', ['capacity' => 250, 'verify_tls' => false, 'notes' => 'Hetzner'] + self::tokenForm());
        $data = $this->decode($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('DE-1', $data['server']['name']);
        self::assertSame([
            'name' => 'DE-1',
            'base_url' => 'https://panel.example:2053/base',
            'auth_mode' => 'token',
            'api_token' => ['set' => true, 'hint' => '••••••ok-1'],
            'username' => '',
            'password' => ['set' => false, 'hint' => ''],
            'totp_secret' => ['set' => false, 'hint' => ''],
            'verify_tls' => false,
            'timeout' => 30,
            'subscription_url' => '',
            'capacity' => 250,
            'notes' => 'Hetzner',
            'is_active' => true,
        ], $data['server']['form'], 'its form as kept: no secret, only whether one is kept');
        self::assertArrayNotHasKey('api_token', $data['server']);
        self::assertSame(250, $data['server']['capacity']);
        self::assertSame('Hetzner', $data['server']['notes']);
        self::assertNotNull($data['server']['last_checked_at']);
        self::assertNull($data['server']['last_error']);
        self::assertTrue($data['probe']['ok']);
        self::assertSame(['inbounds' => 2, 'selectable_inbounds' => 0, 'active_subscriptions' => 0, 'shop_active_subscriptions' => 0], $data['server']['counts']);
        self::assertTrue($data['server']['serves_subscriptions'], 'the check remembers whether the panel serves subscriptions');

        $server = Server::query()->findOrFail($data['server']['id']);
        self::assertSame('tok-1', $server->api_token, 'decrypted transparently by the cast');
        self::assertNotSame('tok-1', $server->getRawOriginal('api_token'), 'stored encrypted');
        self::assertSame(['verify_tls' => false, 'timeout' => 30], $server->meta);
        self::assertSame(1, $server->sort);
        $inbounds = $server->inbounds()->oldest('id')->get();
        self::assertSame(['1', '2'], $inbounds->pluck('remote_key')->all());
        self::assertSame(['reality', 'tls'], $inbounds->pluck('security')->all());
        self::assertSame([1, 0], $inbounds->pluck('client_count')->all());
    }

    public function testAPanelWithoutASubscriptionServerIsRememberedAsUnsellable(): void
    {
        $this->panelHttp()->healthyPanel(subscriptions: false);

        $data = $this->decode($this->postJson('/api/admin/servers', self::tokenForm()));

        self::assertTrue($data['probe']['ok']);
        self::assertFalse($data['probe']['serves_subscriptions']);
        self::assertFalse($data['server']['serves_subscriptions']);

        // Turned on since, and checked again: sellable.
        $this->panelHttp()->healthyPanel();
        $checked = $this->decode($this->postJson("/api/admin/servers/{$data['server']['id']}/test"));
        self::assertTrue($checked['server']['serves_subscriptions']);

        // A check that could not reach the panel does not forget what it knew.
        $this->panelHttp()->fail('unauthorized', 401);
        $failed = $this->decode($this->postJson("/api/admin/servers/{$data['server']['id']}/test"));
        self::assertNotNull($failed['server']['last_error']);
        self::assertFalse($failed['probe']['subscription_probed']);
        self::assertTrue($failed['server']['serves_subscriptions'], 'unchanged: the question was not asked');
    }

    public function testANewServerIsNotSellableUntilItsFirstSuccessfulCheck(): void
    {
        $this->panelHttp()->fail('unauthorized', 401);

        $data = $this->decode($this->postJson('/api/admin/servers', self::tokenForm()));

        self::assertNull($data['server']['serves_subscriptions'], 'never asked');
        self::assertFalse(Server::query()->findOrFail($data['server']['id'])->servesSubscriptions());
    }

    public function testFailedCheckIsRecordedInPersianWithTheDriversHint(): void
    {
        $this->panelHttp()->fail('unauthorized', 401);

        $data = $this->decode($this->postJson('/api/admin/servers', self::tokenForm()));

        self::assertFalse($data['probe']['ok']);
        self::assertStringContainsString('توکن', $data['probe']['error']);
        self::assertStringContainsString('Settings → Security → API Token', $data['probe']['error'], 'where on a 3x-ui panel the token is made');
        self::assertStringContainsString('توکن', $data['server']['last_error']);
        self::assertSame(0, $data['server']['counts']['inbounds']);
    }

    public function testEditingKeepsSecretsUnlessReplaced(): void
    {
        $server = $this->panelServer(overrides: ['meta' => ['verify_tls' => true, 'timeout' => 30]]);

        $data = $this->decode($this->putJson("/api/admin/servers/{$server->id}", ['name' => 'DE-1 (Falkenstein)', 'base_url' => 'https://de-1.example:2053/other-base', 'api_token' => ''] + self::tokenForm()));
        self::assertSame('DE-1 (Falkenstein)', $data['server']['name']);
        self::assertSame('tok-1', $server->refresh()->api_token, 'a blank token keeps the stored one while the panel stays on its host');

        // Moved to another host, the stored token is never sent there: it is typed again.
        $moved = ['base_url' => 'https://elsewhere.example:2053/base', 'api_token' => ''] + self::tokenForm();
        $response = $this->putJson("/api/admin/servers/{$server->id}", $moved);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['api_token'], array_keys($this->decode($response)['errors']));
        self::assertSame('https://de-1.example:2053/other-base', $server->refresh()->base_url, 'nothing changed');
        $response = $this->postJson('/api/admin/servers/test', ['id' => $server->id] + $moved);
        self::assertSame(422, $response->getStatusCode(), 'nor does a probe of the edited form send it');
        self::assertSame([], $this->panelHttp()->calls());

        $login = ['base_url' => 'https://de-1.example:2053/other-base', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'pw', 'totp_secret' => 'jbsw y3dp ehpk 3pxp', 'is_active' => false] + self::tokenForm();
        $data = $this->decode($this->putJson("/api/admin/servers/{$server->id}", $login));
        $form = $data['server']['form'];
        self::assertSame(['password', 'admin'], [$form['auth_mode'], $form['username']]);
        self::assertSame([false, true, true], [$form['api_token']['set'], $form['password']['set'], $form['totp_secret']['set']]);
        self::assertFalse($data['server']['is_active']);
        $server->refresh();
        self::assertNull($server->api_token, 'switching the way in drops the other credential');
        self::assertSame('pw', $server->password);
        self::assertSame('JBSWY3DPEHPK3PXP', $server->totp_secret, 'as an authenticator shows it, kept in one form');

        // The panel's two-factor login switched off: its secret cleared, the login kept.
        $this->putJson("/api/admin/servers/{$server->id}", ['password' => '', 'totp_secret' => '', 'clear_totp_secret' => true] + $login);
        self::assertSame(['pw', null], [$server->refresh()->password, $server->totp_secret]);

        // A token again: the login's secrets go.
        $this->putJson("/api/admin/servers/{$server->id}", ['api_token' => 'tok-2'] + self::tokenForm());
        self::assertSame(['tok-2', null, null], [$server->refresh()->api_token, $server->username, $server->password]);
    }

    public function testAServersFormIsItsDescribedFieldsAsTheServerKeepsThem(): void
    {
        $server = $this->panelServer(overrides: ['api_token' => null, 'username' => 'admin', 'password' => 'pw', 'meta' => ['verify_tls' => false, 'timeout' => 45, 'subscription_url' => 'https://sub.example.com/s']]);
        $this->panelServer('Gone', ['driver' => 'marzban']);

        $fields = array_column(array_column($this->decode($this->get('/api/admin/servers/drivers'))['drivers'], null, 'key')['3x-ui']['fields'], 'name');
        $servers = array_column($this->decode($this->get('/api/admin/servers'))['servers'], 'form', 'name');

        self::assertSame($fields, array_keys((array) $servers['DE-1']), 'its connector\'s fields, nothing else');
        self::assertSame(['password', 'admin', false, 45, 'https://sub.example.com/s'], [$servers['DE-1']['auth_mode'], $servers['DE-1']['username'], $servers['DE-1']['verify_tls'], $servers['DE-1']['timeout'], $servers['DE-1']['subscription_url']]);
        self::assertSame(['set' => true, 'hint' => '••••••••'], $servers['DE-1']['password'], 'a password\'s hint gives none of it away');
        self::assertNull($servers['Gone'], 'a connector this installation has not: nothing of its form to show');
        self::assertSame($servers['DE-1'], $this->decode($this->get("/api/admin/servers/{$server->id}"))['server']['form']);
    }

    public function testInboundsCanBeSyncedAndMarkedSelectable(): void
    {
        $server = $this->panelServer();
        $this->inbound($server, '9', ['tag' => 'old', 'protocol' => 'vmess', 'port' => 80]);

        $this->panelHttp()->ok(FakePanel::inboundList());
        $data = $this->decode($this->postJson("/api/admin/servers/{$server->id}/inbounds/sync"));

        self::assertSame(['9', '1', '2'], array_column($data['inbounds'], 'remote_key'), 'in the order they were first seen');
        self::assertNotNull($data['server']['last_checked_at'], 'a sync is a check');
        self::assertNull($data['server']['last_error']);
        $gone = ServerInbound::query()->where('server_id', $server->id)->where('remote_key', '9')->firstOrFail();
        self::assertFalse($gone->enabled, 'inbounds that vanished from the panel are disabled, not deleted');
        self::assertTrue($gone->is_selectable, 'what the owner chose stays, for when it comes back');
        self::assertSame([], $server->sellableInbounds()->pluck('remote_key')->all(), 'but nothing disabled is sold');

        $vless = ServerInbound::query()->where('server_id', $server->id)->where('remote_key', '1')->firstOrFail();
        $data = $this->decode($this->patchJson("/api/admin/servers/{$server->id}/inbounds/{$vless->id}", ['is_selectable' => true]));
        self::assertTrue($vless->refresh()->is_selectable);
        self::assertSame(['1'], array_values(array_column(array_filter($data['inbounds'], static fn(array $row): bool => $row['is_selectable'] && $row['enabled']), 'remote_key')), 'for sale: the one opted in that the panel has');

        $response = $this->patchJson("/api/admin/servers/{$server->id}/inbounds/{$gone->id}", ['is_selectable' => true]);
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('is_selectable', $this->decode($response)['errors']);
    }

    public function testAnInboundThatComesBackIsSoldAgainAsTheOwnerHadIt(): void
    {
        $server = $this->panelServer();
        $this->inbound($server, '1');

        $this->panelHttp()->ok([]);
        $this->postJson("/api/admin/servers/{$server->id}/inbounds/sync");
        self::assertSame([], $server->sellableInbounds()->pluck('remote_key')->all(), 'a panel with none really has none');

        $this->panelHttp()->ok(FakePanel::inboundList());
        $this->postJson("/api/admin/servers/{$server->id}/inbounds/sync");
        self::assertSame(['1'], $server->sellableInbounds()->pluck('remote_key')->all());
    }

    public function testAProbeLosesOnlyTheAnswersItsFlakyEndpointsCouldNotGive(): void
    {
        $server = $this->panelServer(overrides: ['serves_subscriptions' => true]);
        $this->panelHttp()->ok([]);                       // the connection test
        $this->panelHttp()->fail('database is locked', 500); // the status
        $this->panelHttp()->ok(FakePanel::inboundList());
        $this->panelHttp()->fail('database is locked', 500); // the settings: whether it serves subscription links

        $data = $this->decode($this->postJson("/api/admin/servers/{$server->id}/test"));

        self::assertTrue($data['probe']['ok'], 'the panel answered');
        self::assertNull($data['probe']['status']);
        self::assertSame(['1', '2'], array_column((array) $data['probe']['inbounds'], 'remote_key'), 'the question that worked kept its answer');
        self::assertSame([null, false], [$data['probe']['serves_subscriptions'], $data['probe']['subscription_probed']]);
        self::assertTrue($data['server']['serves_subscriptions'], 'what the check could not ask, it does not forget');
        self::assertNull($data['server']['last_error']);
    }

    public function testTheOwnersCheckAsksAPanelTheShopLeavesAloneAWhile(): void
    {
        // Its last contact failed a minute ago: the tasks and the customers' screens leave it alone for now.
        $server = $this->panelServer(overrides: ['last_error' => 'پنل جواب نداد (تایم‌اوت).', 'last_checked_at' => Carbon::now()->subMinute()]);
        self::assertTrue($server->isBackingOff());
        $this->panelHttp()->healthyPanel();

        $data = $this->decode($this->postJson("/api/admin/servers/{$server->id}/test"));

        self::assertTrue($data['probe']['ok'], 'the owner asked: the panel is asked');
        self::assertNull($data['server']['last_error']);
        self::assertFalse($server->refresh()->isBackingOff());
    }

    public function testEachServerSaysWhyCustomersAreNotSoldOnIt(): void
    {
        $this->panelServer('Ready', ['serves_subscriptions' => true]);
        $this->panelServer('Unchecked');
        $this->panelServer('NoLinks', ['serves_subscriptions' => false]);
        $this->panelServer('Off', ['serves_subscriptions' => true, 'is_active' => false]);
        $this->panelServer('Full', ['serves_subscriptions' => true, 'capacity' => 0]);
        $this->panelServer('Gone', ['serves_subscriptions' => true, 'driver' => 'marzban']);

        $reasons = array_column($this->decode($this->get('/api/admin/servers'))['servers'], 'unsellable_reason', 'name');

        self::assertNull($reasons['Ready']);
        self::assertStringContainsString('بررسی نشده', (string) $reasons['Unchecked'], 'never checked: not known to serve links');
        self::assertStringContainsString('لینک اشتراک نمی‌دهد', (string) $reasons['NoLinks']);
        self::assertStringContainsString('غیرفعال', (string) $reasons['Off']);
        self::assertStringContainsString('ظرفیت', (string) $reasons['Full']);
        self::assertStringContainsString('کانکتور', (string) $reasons['Gone'], 'its connector is not in this installation');
    }

    public function testACheckThatCouldNotListTheInboundsChangesNoneOfThem(): void
    {
        $server = $this->panelServer(overrides: ['serves_subscriptions' => true]);
        $this->inbound($server, '1');
        $this->inbound($server, '2', ['is_selectable' => false]);

        $this->panelHttp()->ok([], ['cpu' => 1, 'xray' => ['state' => 'running']]);
        $this->panelHttp()->fail('database is locked', 500);
        $this->panelHttp()->ok(['subEnable' => true]);
        $data = $this->decode($this->postJson("/api/admin/servers/{$server->id}/test"));

        self::assertTrue($data['probe']['ok'], 'the panel answered; one question failed');
        self::assertNull($data['probe']['inbounds']);
        self::assertNull($data['server']['last_error']);
        $inbounds = $server->inbounds()->oldest('id')->get();
        self::assertSame([true, true], $inbounds->pluck('enabled')->all(), 'a listing that failed is no empty panel');
        self::assertSame([true, false], $inbounds->pluck('is_selectable')->all());
        self::assertSame(['1'], $server->sellableInbounds()->pluck('remote_key')->all());
    }

    public function testAFailedSyncIsRecordedAndAnswered502(): void
    {
        $server = $this->panelServer();
        $this->panelHttp()->raw(new Response(401));

        $response = $this->postJson("/api/admin/servers/{$server->id}/inbounds/sync");

        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('توکن', $this->decode($response)['message']);
        self::assertStringContainsString('توکن', (string) $server->refresh()->last_error);
    }

    public function testAServerWhoseConnectorIsGoneIsNotCheckedButSaidWhy(): void
    {
        $server = $this->panelServer('Gone', ['driver' => 'marzban']);

        foreach (['check' => "/api/admin/servers/{$server->id}/test", 'inbounds' => "/api/admin/servers/{$server->id}/inbounds/sync"] as $what => $path) {
            $response = $this->postJson($path);

            self::assertSame(422, $response->getStatusCode(), $what);
            self::assertSame(['driver' => [ServerReadiness::NO_CONNECTOR]], $this->decode($response)['errors'], $what);
        }
        self::assertSame([], $this->panelHttp()->calls(), 'nothing to ask its panel with');
        self::assertNull($server->refresh()->last_checked_at, 'nor anything recorded');
    }

    public function testDeletingIsRefusedWhileSubscriptionsAreOnTheServer(): void
    {
        $server = $this->panelServer();
        $subscription = $this->subscription($this->customer(), $this->plan(), $server, 'amir_1');

        $response = $this->deleteJson("/api/admin/servers/{$server->id}");
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('منتقل یا حذف کنید', $this->decode($response)['message']);
        self::assertTrue(Server::query()->whereKey($server->id)->exists());

        // One whose client the panel lost is still a row on it: the admin deletes it first.
        $subscription->forceFill(['status' => SubscriptionStatus::Deleted])->save();
        $response = $this->deleteJson("/api/admin/servers/{$server->id}");
        self::assertSame(409, $response->getStatusCode());

        $subscription->delete();
        $response = $this->deleteJson("/api/admin/servers/{$server->id}");
        self::assertSame(204, $response->getStatusCode());
        self::assertFalse(Server::query()->whereKey($server->id)->exists());
    }

    public function testAServersServicesAreEveryBotsWhicheverShopAsks(): void
    {
        $server = $this->panelServer();
        $bot = $this->agentBot();
        CurrentBot::run($bot, fn(): Subscription => $this->subscription($this->customer(['telegram_id' => 2001, 'username' => 'reza']), $this->plan(), $server, 'reza_1'));
        $this->subscription($this->customer(['telegram_id' => 2002]), $this->plan(), $server, 'ali_1');
        $this->subscription($this->customer(['telegram_id' => 2003]), $this->plan(), $server, 'sara_1', ['status' => SubscriptionStatus::Expired]);
        $counts = fn(): array => array_intersect_key($this->decode($this->get("/api/admin/servers/{$server->id}"))['server']['counts'], array_flip(['active_subscriptions', 'shop_active_subscriptions']));

        self::assertSame(2, $this->decode($this->get('/api/admin/servers'))['servers'][0]['counts']['active_subscriptions'], "the agent's bot's service is on it too");
        self::assertSame(['active_subscriptions' => 2, 'shop_active_subscriptions' => 1], $counts(), "of them, the open shop's: what its subscriptions screen lists");
        $this->openShop($bot);
        self::assertSame(['active_subscriptions' => 2, 'shop_active_subscriptions' => 1], $counts(), "the agent's shop open: its own");
        self::assertSame(409, $this->deleteJson("/api/admin/servers/{$server->id}")->getStatusCode(), 'so it is not deleted from under it');
    }

    public function testListingCarriesCountsAndNoSecrets(): void
    {
        $server = $this->panelServer(overrides: ['api_token' => null, 'username' => 'admin', 'password' => 'pw']);
        $this->inbound($server, '1');

        $data = $this->decode($this->get('/api/admin/servers'));

        self::assertCount(1, $data['servers']);
        $row = $data['servers'][0];
        self::assertSame(['password', 'admin'], [$row['form']['auth_mode'], $row['form']['username']]);
        self::assertStringNotContainsString('pw', json_encode($row, JSON_THROW_ON_ERROR));
        self::assertSame(['inbounds' => 1, 'selectable_inbounds' => 1, 'active_subscriptions' => 0, 'shop_active_subscriptions' => 0], $row['counts']);
        self::assertSame('3X-UI', $row['driver_label']);
        self::assertNull($row['serves_subscriptions']);

        $data = $this->decode($this->get("/api/admin/servers/{$server->id}"));
        self::assertSame('DE-1', $data['server']['name']);
        self::assertSame(['1'], array_column($data['inbounds'], 'remote_key'));
        self::assertSame(404, $this->get('/api/admin/servers/999')->getStatusCode());
    }

    /** @return array<string, mixed> A 3X-UI server's form with an API token, as the screen sends it */
    private static function tokenForm(): array
    {
        return ['driver' => '3x-ui', 'name' => 'DE-1', 'base_url' => 'https://panel.example:2053/base/', 'auth_mode' => 'token', 'api_token' => 'tok-1', 'verify_tls' => true, 'timeout' => 30, 'is_active' => true];
    }
}
