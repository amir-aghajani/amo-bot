<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\ThreeXui;

use App\Modules\Providers\Drivers\ThreeXui\DTO\ClientPayload;
use App\Modules\Providers\Drivers\ThreeXui\ThreeXuiApi;
use App\Modules\Providers\Drivers\ThreeXui\ThreeXuiClient;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;

/**
 * The shop's half of the 3x-ui contract: every request the connector sends — each ThreeXuiApi call, and the client's
 * sign-in handshake — is an operation the panel's own description (tests/Fixtures/3x-ui-openapi.json, 3x-ui v3) has:
 * that path with that method, a JSON body where the panel takes one and only fields it documents, no body where it
 * takes none. The connector wraps nothing else (a reflection guard keeps one case per ThreeXuiApi method).
 */
final class ThreeXuiContractTest extends TestCase
{
    private const BASE_PATH = '/AbCdEf';

    /** The operations whose body is (or carries, under the key) a client row: its fields are the Client schemas'. */
    private const CLIENT_ROWS = [
        'POST /panel/api/clients/add' => 'client',
        'POST /panel/api/clients/update/{email}' => null,
    ];

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    private FakePanel $panel;

    /** @return iterable<string, array{\Closure(ThreeXuiApi): mixed, list<string>}> The call, and the requests it sends */
    public static function calls(): iterable
    {
        $client = new ClientPayload(email: 'amir_1', totalBytes: 1 << 30, expiryTime: -86_400_000, limitIp: 2, tgId: 42, comment: '42 | amir');
        $kept = $client->with(['subId' => 'sub-1', 'id' => 'e18c9a96-71bf-48d4-933f-8b9a46d4290c', 'flow' => 'xtls-rprx-vision', 'allowedIPs' => ['10.0.0.2/32']]);

        yield 'inboundOptions' => [static fn(ThreeXuiApi $api) => $api->inboundOptions(), ['GET /panel/api/inbounds/options']];
        yield 'inbounds' => [static fn(ThreeXuiApi $api) => $api->inbounds(), ['GET /panel/api/inbounds/list/slim']];
        yield 'status' => [static fn(ThreeXuiApi $api) => $api->status(), ['GET /panel/api/server/status']];
        yield 'settings' => [static fn(ThreeXuiApi $api) => $api->settings(), ['POST /panel/api/setting/all']];
        yield 'clients' => [static fn(ThreeXuiApi $api) => $api->clients(), ['GET /panel/api/clients/list']];
        yield 'client' => [static fn(ThreeXuiApi $api) => $api->client('amir_1'), ['GET /panel/api/clients/get/amir_1']];
        yield 'traffic' => [static fn(ThreeXuiApi $api) => $api->traffic('amir_1'), ['GET /panel/api/clients/traffic/amir_1']];
        yield 'onlines' => [static fn(ThreeXuiApi $api) => $api->onlines(), ['POST /panel/api/clients/onlines']];
        yield 'addClient' => [static fn(ThreeXuiApi $api) => $api->addClient($client, [3, 5]), ['POST /panel/api/clients/add']];
        yield 'updateClient' => [static fn(ThreeXuiApi $api) => $api->updateClient('amir_1', $kept), ['POST /panel/api/clients/update/amir_1']];
        yield 'deleteClient' => [static fn(ThreeXuiApi $api) => $api->deleteClient('amir_1'), ['POST /panel/api/clients/del/amir_1']];
        yield 'resetClientTraffic' => [static fn(ThreeXuiApi $api) => $api->resetClientTraffic('amir_1'), ['POST /panel/api/clients/resetTraffic/amir_1']];
        yield 'setClientEnabled (on)' => [static fn(ThreeXuiApi $api) => $api->setClientEnabled('amir_1', true), ['POST /panel/api/clients/bulkEnable']];
        yield 'setClientEnabled (off)' => [static fn(ThreeXuiApi $api) => $api->setClientEnabled('amir_1', false), ['POST /panel/api/clients/bulkDisable']];
    }

    /**
     * @param \Closure(ThreeXuiApi): mixed $call
     * @param list<string> $expected
     */
    #[DataProvider('calls')]
    public function testEveryCallIsAnOperationThePanelDescribes(\Closure $call, array $expected): void
    {
        $call($this->api(AuthMode::Token));

        self::assertSame($expected, $this->sent());
        foreach ($this->panel->history as $entry) {
            self::assertDocumented($entry['request']);
            self::assertSame('Bearer tok', $entry['request']->getHeaderLine('Authorization'));
        }
    }

    public function testTheSignInHandshakeIsDescribedToo(): void
    {
        // A cookie login refused, so every step of it is taken: the CSRF token, the login, the two-factor question.
        $api = $this->api(AuthMode::Password, [
            FakePanel::success('csrf-1'),
            new Response(200, ['Content-Type' => 'application/json'], '{"success":false,"msg":"Invalid username or password"}'),
            FakePanel::success(false),
        ]);

        try {
            $api->status();
            self::fail('the refused login must surface');
        } catch (AuthenticationException) {
        }

        self::assertSame(['GET /csrf-token', 'POST /login', 'POST /getTwoFactorEnable'], $this->sent());
        foreach ($this->panel->history as $entry) {
            self::assertDocumented($entry['request']);
        }

        // Signed in with a one-time code: the login's third field.
        $api = $this->api(AuthMode::Password, [FakePanel::success('csrf-1'), new Response(200, ['Content-Type' => 'application/json'], '{"success":true}')], totp: 'JBSWY3DPEHPK3PXP');
        $api->status();

        self::assertSame(['GET /csrf-token', 'POST /login', 'GET /panel/api/server/status'], $this->sent());
        self::assertSame(['username', 'password', 'twoFactorCode'], array_keys($this->panel->params(1)));
        foreach ($this->panel->history as $entry) {
            self::assertDocumented($entry['request']);
        }
    }

    public function testEveryApiMethodHasACase(): void
    {
        $covered = array_unique(array_map(static fn(string $case): string => (string) strtok($case, ' '), array_keys(iterator_to_array(self::calls()))));
        $methods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            array_filter((new \ReflectionClass(ThreeXuiApi::class))->getMethods(\ReflectionMethod::IS_PUBLIC), static fn(\ReflectionMethod $method): bool => !$method->isConstructor()),
        );

        self::assertEqualsCanonicalizing(array_values($methods), array_values($covered), 'a ThreeXuiApi method without a contract case, or a case for one that is gone');
    }

    /** The request is an operation of the description, with the body the description gives it. */
    private static function assertDocumented(RequestInterface $request): void
    {
        $method = $request->getMethod();
        $path = substr($request->getUri()->getPath(), strlen(self::BASE_PATH));
        self::assertStringStartsWith(self::BASE_PATH, $request->getUri()->getPath(), 'every request goes under the web base path');

        [$template, $operation] = self::operation($method, $path);
        self::assertNotNull($operation, "{$method} {$path} is not in the panel's description");

        $body = (string) $request->getBody();
        $described = $operation['requestBody']['content'] ?? null;
        if (!is_array($described)) {
            self::assertSame('', $body, "{$method} {$template} takes no body");

            return;
        }

        self::assertArrayHasKey('application/json', $described, "{$method} {$template} takes no JSON");
        self::assertStringStartsWith('application/json', $request->getHeaderLine('Content-Type'));
        $sent = json_decode($body, true);
        self::assertIsArray($sent, "{$method} {$template} is sent a JSON object");

        $key = $method . ' ' . $template;
        $schema = $described['application/json'];
        if (array_key_exists($key, self::CLIENT_ROWS)) {
            $field = self::CLIENT_ROWS[$key];
            $row = $field === null ? $sent : $sent[$field];
            self::assertIsArray($row);
            self::assertSame([], array_values(array_diff(array_keys($row), self::clientFields())), "{$key} is sent client fields the panel does not describe");
            if ($field === null) {
                return;
            }
        }

        $known = array_merge(array_keys($schema['schema']['properties'] ?? []), array_keys(is_array($schema['example'] ?? null) ? $schema['example'] : []));
        self::assertSame([], array_values(array_diff(array_keys($sent), $known)), "{$key} is sent fields the panel does not describe");
        foreach ($schema['schema']['required'] ?? [] as $required) {
            if ($required !== 'twoFactorCode') { // documented as required, but only sent with the panel's two-factor login on
                self::assertArrayHasKey($required, $sent, "{$key} needs {$required}");
            }
        }
    }

    /**
     * The described operation `$method $path` is, with the template that matched.
     *
     * @return array{string, array<string, mixed>|null}
     */
    private static function operation(string $method, string $path): array
    {
        foreach (self::spec()['paths'] as $template => $operations) {
            $pattern = '#^' . preg_replace('/\\\{[^}]+\\\}/', '[^/]+', preg_quote((string) $template, '#')) . '$#';
            if (preg_match($pattern, $path) === 1 && isset($operations[strtolower($method)])) {
                return [(string) $template, $operations[strtolower($method)]];
            }
        }

        return [$path, null];
    }

    /** @return list<string> The fields of a client row, as the description spells it on write and on read. */
    private static function clientFields(): array
    {
        $schemas = self::spec()['components']['schemas'];

        return array_values(array_unique(array_merge(array_keys($schemas['Client']['properties']), array_keys($schemas['ClientRecord']['properties']))));
    }

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return self::$spec ??= json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/3x-ui-openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> The requests sent, "METHOD /path" under the base path. */
    private function sent(): array
    {
        return array_map(static fn(string $call): string => str_replace(' ' . self::BASE_PATH . '/', ' /', $call), $this->panel->calls());
    }

    /** @param list<Response> $answers */
    private function api(AuthMode $mode, array $answers = [], ?string $totp = null): ThreeXuiApi
    {
        $this->panel = new FakePanel();
        $this->panel->raw(...$answers);

        $credentials = $mode === AuthMode::Token ? new PanelCredentials(AuthMode::Token, token: 'tok') : new PanelCredentials(AuthMode::Password, username: 'admin', password: 'pw');

        return new ThreeXuiApi(new ThreeXuiClient(new PanelHttp($this->panel->client(), new NullLogger()), new PanelConnection('https://panel.test:2053' . self::BASE_PATH), $credentials, $totp));
    }
}
