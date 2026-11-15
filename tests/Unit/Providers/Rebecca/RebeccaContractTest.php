<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Rebecca;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\Drivers\Rebecca\RebeccaClient;
use App\Modules\Providers\Drivers\Rebecca\RebeccaProvider;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\AuthMode;
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
 * Every request the Rebecca connector sends — each ProviderInterface method's — is an operation of Rebecca's own
 * description (tests/Fixtures/rebecca-openapi.json, v1.9.1's, trimmed to those operations): that method and path, only
 * documented query parameters, the admin's key as the bearer on every operation the description secures and none on a
 * public subscription route, and a body only where one is taken. Rebecca describes no body schema, so a body is held to
 * the fields its handlers read (internal/app/user/mutation_dto.go) and to what its validation takes (validation.go). A
 * reflection guard keeps a case per ProviderInterface method.
 */
final class RebeccaContractTest extends TestCase
{
    /** A reverse proxy's path in front of the panel: every request goes under it. */
    private const BASE_PATH = '/rb';

    private const KEY = 'rk_q8Xv2kL0bN7rT4yZ1aS6dF3gH9jK5mP2wE4tY6uI8o_';
    private const TOKEN = 'YW1pcl8xLDE3NjAyNjI0MDA8f3Kq1ZbXy';
    private const CREDENTIAL = 'b1c0e3f412344abc8def0123456789ab';

    /**
     * The fields each write's handler reads: POST /api/user with a service_id is UserServiceCreate, PUT is UserModify (its
     * UserPayloadBase, status and service_id) — `next_plan` is refused by both (service.go).
     */
    private const BODIES = [
        'POST /api/user' => ['username', 'service_id', 'status', 'expire', 'data_limit', 'data_limit_reset_strategy', 'note', 'on_hold_timeout', 'on_hold_expire_duration', 'auto_delete_in_days', 'next_plans', 'ip_limit', 'speed_limit_kbps', 'flow', 'credential_key'],
        'PUT /api/user/{username}' => ['credential_key', 'proxies', 'flow', 'expire', 'data_limit', 'data_limit_reset_strategy', 'inbounds', 'note', 'telegram_id', 'contact_number', 'on_hold_expire_duration', 'on_hold_timeout', 'ip_limit', 'speed_limit_kbps', 'auto_delete_in_days', 'next_plans', 'status', 'service_id'],
    ];

    /** The statuses each write takes (validation.go ValidateUserServiceCreate, ValidateUserModify). */
    private const STATUSES = [
        'POST /api/user' => ['active', 'on_hold'],
        'PUT /api/user/{username}' => ['active', 'disabled', 'on_hold'],
    ];

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    private FakePanel $panel;

    /**
     * @return iterable<string, array{\Closure(ProviderInterface): mixed, list<Response>, list<string>}> The call, the
     *                                                                                                   panel's answers, and the requests it sends
     */
    public static function calls(): iterable
    {
        $waiting = new ClientSpec('amir_1', 1 << 30, Expiry::afterFirstUse(2_592_000), 2, 42, '42 | amir');
        $fixed = new ClientSpec('amir_1', 0, Expiry::at(new \DateTimeImmutable('2030-01-01 00:00:00 UTC')), comment: '42 | amir', enabled: false);
        $endless = new ClientSpec('amir_1', 10 << 30, Expiry::never(), 1);
        $dated = self::user(['status' => 'active', 'expire' => 1893456000, 'on_hold_expire_duration' => null]);
        $page = self::json(['users' => [self::listed(), self::listed(['username' => 'sara_2', 'status' => 'on_hold', 'expire' => null])], 'link_templates' => new \stdClass(), 'total' => 2, 'status_breakdown' => new \stdClass()]);

        yield 'testConnection' => [static fn(ProviderInterface $p) => $p->testConnection(), [$page], ['GET /api/users']];
        yield 'status' => [static fn(ProviderInterface $p) => $p->status(), [self::json(['cpu_usage' => 3.5, 'memory' => ['current' => 1, 'total' => 4, 'percent' => 25.0], 'disk' => ['current' => 1, 'total' => 8, 'percent' => 12.5], 'uptime_seconds' => 60, 'xray_running' => true, 'xray_version' => '25.10.31', 'last_xray_error' => null])], ['GET /api/system']];
        yield 'listInbounds' => [static fn(ProviderInterface $p) => $p->listInbounds(), [self::json(['services' => [['id' => 3, 'name' => 'Germany', 'description' => null, 'usage_coefficient' => 1.0, 'used_traffic' => 0, 'lifetime_used_traffic' => 0, 'host_count' => 2, 'user_count' => 5, 'has_hosts' => true, 'broken' => false]], 'total' => 1])], ['GET /api/v2/services']];
        yield 'servesSubscriptions' => [static fn(ProviderInterface $p) => $p->servesSubscriptions(), [], []];
        yield 'createClient (on hold)' => [static fn(ProviderInterface $p) => $p->createClient(['3'], $waiting), [self::json(self::user(), 201)], ['POST /api/user']];
        yield 'createClient (a deadline, switched off)' => [static fn(ProviderInterface $p) => $p->createClient(['3'], $fixed), [self::json($dated, 201), self::json(self::user(['status' => 'disabled']))], ['POST /api/user', 'PUT /api/user/amir_1']];
        yield 'createClient (no end, its detail read again)' => [static fn(ProviderInterface $p) => $p->createClient(['3'], $endless), [self::json(['id' => 7, 'username' => 'amir_1', 'status' => 'active', 'detail' => 'User mutation completed, but the full user detail response could not be generated.'], 201), self::json(self::user())], ['POST /api/user', 'GET /api/user/amir_1']];
        yield 'updateClient (on hold, from a deadline)' => [static fn(ProviderInterface $p) => $p->updateClient($waiting), [self::json($dated), self::json(self::user())], ['GET /api/user/amir_1', 'PUT /api/user/amir_1']];
        yield 'updateClient (a deadline, switched off)' => [static fn(ProviderInterface $p) => $p->updateClient($fixed), [self::json(self::user()), self::json(self::user(['status' => 'disabled']))], ['GET /api/user/amir_1', 'PUT /api/user/amir_1']];
        yield 'updateClient (no end)' => [static fn(ProviderInterface $p) => $p->updateClient($endless), [self::json($dated), self::json(self::user())], ['GET /api/user/amir_1', 'PUT /api/user/amir_1']];
        yield 'rotateClientCredentials' => [static fn(ProviderInterface $p) => $p->rotateClientCredentials('amir_1'), [self::json(self::user())], ['POST /api/user/amir_1/revoke_sub']];
        yield 'setClientEnabled (off)' => [static fn(ProviderInterface $p) => $p->setClientEnabled('amir_1', false), [self::json($dated), self::json(self::user(['status' => 'disabled']))], ['GET /api/user/amir_1', 'PUT /api/user/amir_1']];
        yield 'setClientEnabled (on, back on hold)' => [static fn(ProviderInterface $p) => $p->setClientEnabled('amir_1', true), [self::json(self::user(['status' => 'disabled'])), self::json(self::user())], ['GET /api/user/amir_1', 'PUT /api/user/amir_1']];
        yield 'setClientEnabled (on, its limit and deadline)' => [static fn(ProviderInterface $p) => $p->setClientEnabled('amir_1', true), [self::json(['status' => 'disabled'] + $dated), self::json($dated)], ['GET /api/user/amir_1', 'PUT /api/user/amir_1']];
        yield 'deleteClient' => [static fn(ProviderInterface $p) => $p->deleteClient('amir_1'), [self::json(['username' => 'amir_1', 'status' => 'deleted'])], ['DELETE /api/user/amir_1']];
        yield 'findClient' => [static fn(ProviderInterface $p) => $p->findClient('amir_1', presence: true), [self::json(self::user()), self::json(['amir_1'])], ['GET /api/user/amir_1', 'GET /api/users/onlines']];
        yield 'listClients' => [static fn(ProviderInterface $p) => $p->listClients(), [self::json(['id' => 1, 'username' => 'admin', 'role' => 'sudo']), $page, self::json(self::user(['username' => 'sara_2']))], ['GET /api/admin', 'GET /api/users', 'GET /api/user/sara_2']];
        yield 'findClientByLink (a link)' => [static fn(ProviderInterface $p) => $p->findClientByLink(self::link('https://sub.example.com/sub/' . self::TOKEN)), [$page, self::json(['user' => ['username' => 'amir_1']]), self::json(self::user())], ['GET /api/users', 'GET /sub/' . self::TOKEN . '/info', 'GET /api/user/amir_1']];
        yield 'findClientByLink (a config)' => [static fn(ProviderInterface $p) => $p->findClientByLink(self::link('vless://b1c0e3f4-1234-4abc-8def-0123456789ab@rb.example:443?security=reality#amir_1')), [$page, self::json(['user' => ['username' => 'amir_1']]), self::json(self::user())], ['GET /api/users', 'GET /sub/' . self::CREDENTIAL . '/info', 'GET /api/user/amir_1']];
        yield 'resetClientTraffic' => [static fn(ProviderInterface $p) => $p->resetClientTraffic('amir_1'), [self::json(self::user())], ['POST /api/user/amir_1/reset']];
    }

    /**
     * @param \Closure(ProviderInterface): mixed $call
     * @param list<Response> $answers
     * @param list<string> $expected
     */
    #[DataProvider('calls')]
    public function testEveryCallIsAnOperationThePanelDescribes(\Closure $call, array $answers, array $expected): void
    {
        $call($this->provider($answers));

        self::assertSame($expected, $this->sent());
        foreach ($this->panel->history as $entry) {
            self::assertDocumented($entry['request']);
        }
    }

    public function testEveryProviderMethodHasACase(): void
    {
        $covered = array_unique(array_map(static fn(string $case): string => (string) strtok($case, ' '), array_keys(iterator_to_array(self::calls()))));
        $methods = array_map(static fn(\ReflectionMethod $method): string => $method->getName(), (new \ReflectionClass(ProviderInterface::class))->getMethods());

        self::assertEqualsCanonicalizing($methods, array_values($covered), 'a ProviderInterface method without a contract case, or a case for one that is gone');
    }

    /** The fixture is the release's description, trimmed, and says so. */
    public function testTheDescriptionIsRebeccasOwnTrimmed(): void
    {
        self::assertStringContainsString('v1.9.1', (string) (self::spec()['x-trimmed-from'] ?? ''));
        self::assertSame('Rebecca API', self::spec()['info']['title']);
        self::assertSame([['cookieAuth' => []], ['bearerAuth' => []]], self::spec()['security'], 'secured unless an operation says otherwise');
    }

    /** The request is an operation of the description, with the query, credentials and body the panel takes. */
    private static function assertDocumented(RequestInterface $request): void
    {
        $method = $request->getMethod();
        self::assertStringStartsWith(self::BASE_PATH . '/', $request->getUri()->getPath(), 'every request goes under the panel\'s address');
        $path = substr($request->getUri()->getPath(), strlen(self::BASE_PATH));

        [$template, $operation] = self::operation($method, $path);
        self::assertNotNull($operation, "{$method} {$path} is not in the panel's description");
        $key = "{$method} {$template}";

        // Names only: services.go reads `limit=null` as every service, though the description says an integer.
        parse_str($request->getUri()->getQuery(), $query);
        $described = array_column(array_filter(array_map(self::resolve(...), $operation['parameters'] ?? []), static fn(array $parameter): bool => $parameter['in'] === 'query'), 'name');
        self::assertSame([], array_values(array_diff(array_keys($query), $described)), "{$key} is sent query parameters the panel does not describe");

        // The key where the operation is secured (the description's own security, or its default), never on a public route.
        $secured = ($operation['security'] ?? self::spec()['security']) !== [];
        self::assertSame($secured, $request->hasHeader('Authorization'), "{$key}: the key exactly where it is asked for");
        if ($secured) {
            self::assertSame('Bearer ' . self::KEY, $request->getHeaderLine('Authorization'));
        }

        $body = (string) $request->getBody();
        if (!isset($operation['requestBody'])) {
            self::assertSame('', $body, "{$key} takes no body");

            return;
        }

        self::assertStringStartsWith('application/json', $request->getHeaderLine('Content-Type'), "{$key} takes JSON");
        $sent = json_decode($body, true);
        self::assertIsArray($sent, "{$key} is sent an object");
        self::assertSame([], array_values(array_diff(array_keys($sent), self::BODIES[$key] ?? [])), "{$key} is sent fields its handler does not read");
        self::assertNull(self::misfit($key, $sent), "{$key}: " . self::misfit($key, $sent));
    }

    /**
     * Why Rebecca's validation would refuse `$sent` (validation.go, permissions.go); null when it takes it.
     *
     * @param array<string, mixed> $sent
     */
    private static function misfit(string $key, array $sent): ?string
    {
        if ($key === 'POST /api/user') {
            if (!is_string($sent['username'] ?? null) || preg_match('/^[a-zA-Z0-9._@-]{3,32}$/', $sent['username']) !== 1) {
                return 'username is not a name Rebecca takes';
            }
            if (!is_int($sent['service_id'] ?? null) || $sent['service_id'] <= 0) {
                return 'service_id is required, a positive integer';
            }
        }
        foreach (['service_id', 'expire', 'data_limit', 'on_hold_expire_duration', 'ip_limit'] as $field) {
            if (array_key_exists($field, $sent) && (!is_int($sent[$field]) || $sent[$field] < 0)) {
                return "{$field} is no whole number of 0 or more";
            }
        }
        $status = $sent['status'] ?? null;
        if ($status !== null && !in_array($status, self::STATUSES[$key], true)) {
            return 'status ' . json_encode($status) . ' is not taken here';
        }
        if (array_key_exists('data_limit_reset_strategy', $sent) && !in_array($sent['data_limit_reset_strategy'], ['no_reset', 'day', 'week', 'month', 'year'], true)) {
            return 'no such data_limit_reset_strategy';
        }
        if (array_key_exists('note', $sent) && (!is_string($sent['note']) || strlen(trim($sent['note'])) > 500)) {
            return 'note is no string of at most 500 bytes';
        }
        // validateOnHold: on hold needs its duration and no deadline.
        if ($status === 'on_hold' && (($sent['on_hold_expire_duration'] ?? 0) <= 0 || ($sent['expire'] ?? 0) > 0)) {
            return 'on hold without a duration, or with a deadline';
        }

        return null;
    }

    /**
     * A parameter with its reference followed.
     *
     * @param array<string, mixed> $parameter
     * @return array<string, mixed>
     */
    private static function resolve(array $parameter): array
    {
        while (isset($parameter['$ref'])) {
            $parameter = self::spec()['components']['parameters'][substr($parameter['$ref'], strlen('#/components/parameters/'))];
        }

        return $parameter;
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

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return self::$spec ??= json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/rebecca-openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> The requests sent, "METHOD /path" under the panel's address. */
    private function sent(): array
    {
        return array_map(static fn(string $call): string => str_replace(' ' . self::BASE_PATH . '/', ' /', $call), $this->panel->calls());
    }

    /** @param list<Response> $answers */
    private function provider(array $answers): ProviderInterface
    {
        $this->panel = new FakePanel();
        $this->panel->raw(...$answers);
        $connection = new PanelConnection('https://rb.test:8000' . self::BASE_PATH);

        return new RebeccaProvider(new RebeccaClient(new PanelHttp($this->panel->client(), new NullLogger()), $connection, new PanelCredentials(AuthMode::Token, token: self::KEY)), $connection);
    }

    /**
     * A user's detail as GET /api/user/{username} answers it, on hold a month.
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
            'expire' => null,
            'data_limit' => 1 << 30,
            'on_hold_expire_duration' => 2_592_000,
            'online_at' => null,
            'ip_limit' => 2,
            'service_id' => 3,
            'next_plans' => [],
            'subscription_url' => '/sub/' . self::CREDENTIAL,
            'subscription_urls' => ['key' => '/sub/' . self::CREDENTIAL, 'token' => '/sub/' . self::TOKEN],
        ];
    }

    /**
     * A user as GET /api/users lists it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function listed(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'username' => 'amir_1',
            'status' => 'active',
            'used_traffic' => 0,
            'expire' => 1893456000,
            'data_limit' => 1 << 30,
            'online_at' => null,
            'is_online' => false,
            'service_id' => 3,
            'subscription_url' => '/sub/' . self::CREDENTIAL,
            'subscription_urls' => ['key' => '/sub/' . self::CREDENTIAL, 'token' => '/sub/' . self::TOKEN],
        ];
    }

    private static function json(mixed $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    private static function link(string $text): ClientLink
    {
        return ClientLink::find($text) ?? self::fail("No link in {$text}.");
    }
}
