<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Api\BotApi;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Tests\HttpTestCase;

/**
 * «پراکسی SOCKS5»: a server whose panel the shop's host cannot reach directly is reached through a proxy — set on its
 * form, its password kept encrypted and only for the same proxy —, every request to that panel goes through it (a check,
 * a probe of the form) and nothing else the shop sends does (Telegram, the shared client), and a failure at the proxy is
 * said as the proxy's, with nothing of its login, and leaves the panel alone a while like any panel out of reach.
 */
final class PanelProxyTest extends HttpTestCase
{
    private const PASSWORD = 'p@ss:w/rd';

    /** The proxy as cURL is handed it: its login URL-encoded. */
    private const URL = 'socks5h://shop:p%40ss%3Aw%2Frd@proxy.example.com:1080';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testAProxyIsSetOnTheServersFormItsPasswordKeptEncryptedAndNeverShown(): void
    {
        $server = $this->panelServer();

        $response = $this->putJson("/api/admin/servers/{$server->id}", self::form(self::proxy()));
        $form = $this->decode($response)['server']['form'];

        self::assertSame([true, 'proxy.example.com', 1080, 'shop'], [$form['proxy_enabled'], $form['proxy_host'], $form['proxy_port'], $form['proxy_username']]);
        self::assertSame(['set' => true, 'hint' => '••••••••'], $form['proxy_password'], 'whether one is kept, nothing of it');
        self::assertStringNotContainsString('p@ss', (string) $response->getBody());
        $server->refresh();
        self::assertSame(self::PASSWORD, $server->proxy_password, 'decrypted by the cast');
        self::assertStringNotContainsString('p@ss', (string) $server->getRawOriginal('proxy_password'), 'stored encrypted');
        self::assertArrayNotHasKey('proxy', (array) $server->meta, 'nothing of it in the plain meta');

        $this->putJson("/api/admin/servers/{$server->id}", self::form(['proxy_password' => ''] + self::proxy()));
        self::assertSame(self::PASSWORD, $server->refresh()->proxy_password, 'left blank: kept');

        $moved = $this->putJson("/api/admin/servers/{$server->id}", self::form(['proxy_host' => 'proxy2.example.com', 'proxy_password' => ''] + self::proxy()));
        self::assertSame(422, $moved->getStatusCode());
        self::assertSame(['proxy_password'], array_keys($this->decode($moved)['errors']), 'never sent to another proxy');
        self::assertSame('proxy.example.com', $server->refresh()->proxy_host, 'nothing changed');

        $this->putJson("/api/admin/servers/{$server->id}", self::form(['proxy_enabled' => false]));
        $server->refresh();
        self::assertSame([false, null, null, null, null], [$server->proxy_enabled, $server->proxy_host, $server->proxy_port, $server->proxy_username, $server->proxy_password], 'switched off: forgotten');
    }

    public function testEveryRequestToItsPanelGoesThroughTheProxyAndNothingElseDoes(): void
    {
        $proxied = $this->panelServer('DE-1', self::columns());
        $direct = $this->panelServer('NL-1');

        $this->panelHttp()->healthyPanel();
        self::assertTrue($this->decode($this->postJson("/api/admin/servers/{$proxied->id}/test"))['probe']['ok']);
        $requests = count($this->panelHttp()->history);
        self::assertGreaterThanOrEqual(4, $requests, 'the sign-in check, the status, the inbounds, the settings');
        for ($i = 0; $i < $requests; ++$i) {
            self::assertSame(self::URL, $this->panelHttp()->options($i)['proxy'] ?? null, $this->panelHttp()->calls()[$i]);
        }

        // The same process, the same client and transport: every other request goes straight out.
        $this->panelHttp()->reset();
        $this->panelHttp()->healthyPanel();
        $this->postJson("/api/admin/servers/{$direct->id}/test");
        $this->service(ClientInterface::class)->request('GET', 'https://api.github.com/repos/example/releases');
        foreach ($this->panelHttp()->history as $i => $entry) {
            self::assertNotSame(self::URL, $entry['options']['proxy'] ?? null, "{$entry['method']} {$entry['path']} (#{$i})");
        }

        $telegram = $this->telegram();
        $this->service(BotApi::class)->sendMessage(42, 'سلام');
        self::assertNotEmpty($telegram->history);
        foreach ($telegram->history as $call) {
            self::assertNotSame(self::URL, $call['options']['proxy'] ?? null, "Telegram's {$call['method']}");
        }
    }

    public function testTheFormIsProbedThroughItsProxyAStoredPasswordStandingInForABlankOne(): void
    {
        $this->panelHttp()->healthyPanel();
        $probe = $this->decode($this->postJson('/api/admin/servers/test', self::form(self::proxy())))['probe'];

        self::assertTrue($probe['ok']);
        self::assertSame(self::URL, $this->panelHttp()->options(0)['proxy'] ?? null, 'a server not saved yet');
        self::assertSame(0, Server::query()->count());

        $server = $this->panelServer('DE-1', self::columns());
        $this->panelHttp()->reset();
        $this->panelHttp()->healthyPanel();
        $this->postJson('/api/admin/servers/test', ['id' => $server->id] + self::form(['proxy_password' => ''] + self::proxy()));
        self::assertSame(self::URL, $this->panelHttp()->options(0)['proxy'] ?? null, 'its stored password, for the same proxy');
    }

    public function testAFailureAtTheProxyIsTheProxysSaidWithoutItsLoginAndTheServerIsLeftAlone(): void
    {
        $server = $this->panelServer('DE-1', self::columns());
        $this->panelHttp()->refuse(new ConnectException('cURL error 97: User was rejected by the SOCKS5 server (1 1) at ' . self::URL . ' (see https://curl.se/libcurl/c/libcurl-errors.html) for https://de-1.example:2053/base/panel/api/inbounds/list', new Request('GET', 'x')));

        $response = $this->postJson("/api/admin/servers/{$server->id}/inbounds/sync");
        $body = (string) $response->getBody();
        $server->refresh();

        self::assertStringStartsWith('اتصال از طریق پراکسی SOCKS5 سرور برقرار نشد', (string) $server->last_error, 'the proxy is named, not the panel\'s name or port');
        self::assertStringNotContainsString('نام دامنه پنل', (string) $server->last_error);
        self::assertTrue($server->isBackingOff(), 'counted like a panel out of reach: left alone a while');
        foreach ([$body, (string) $server->last_error] as $said) {
            self::assertStringNotContainsString('p%40ss', $said);
            self::assertStringNotContainsString('shop:', $said);
            self::assertStringNotContainsString('/base', $said, 'nor the panel\'s path');
        }
        self::assertStringContainsString('[address]', (string) $server->last_error);
    }

    /**
     * A 3x-ui server's whole form, by its token.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function form(array $overrides = []): array
    {
        return $overrides + ['driver' => '3x-ui', 'name' => 'DE-1', 'base_url' => 'https://de-1.example:2053/base', 'auth_mode' => 'token', 'api_token' => 'tok-1', 'verify_tls' => true, 'timeout' => 30, 'proxy_enabled' => false, 'is_active' => true];
    }

    /** @return array<string, mixed> The proxy's fields, as the form takes them. */
    private static function proxy(): array
    {
        return ['proxy_enabled' => true, 'proxy_host' => 'proxy.example.com', 'proxy_port' => 1080, 'proxy_username' => 'shop', 'proxy_password' => self::PASSWORD];
    }

    /** @return array<string, mixed> The proxy as a server keeps it. */
    private static function columns(): array
    {
        return ['proxy_enabled' => true, 'proxy_host' => 'proxy.example.com', 'proxy_port' => 1080, 'proxy_username' => 'shop', 'proxy_password' => self::PASSWORD];
    }
}
