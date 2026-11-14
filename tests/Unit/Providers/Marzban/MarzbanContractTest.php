<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Marzban;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\Drivers\Marzban\MarzbanClient;
use App\Modules\Providers\Drivers\Marzban\MarzbanProvider;
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
 * Every request the Marzban connector sends — each ProviderInterface method's, and the sign-in before them — is an
 * operation of Marzban's own description (tests/Fixtures/marzban-openapi.json, Marzban 0.8.4's, written from its source:
 * the panel publishes none but the one a running panel serves): that method and path, only documented query parameters,
 * a body only where one is taken — JSON or a form, as described — with documented fields whose values fit their schemas,
 * and the admin's token on every operation that asks for it, never on a public one. A reflection guard keeps a case per
 * ProviderInterface method.
 */
final class MarzbanContractTest extends TestCase
{
    /** A reverse proxy's path in front of the panel: every request goes under it. */
    private const BASE_PATH = '/mz';

    private const TOKEN = 'YW1pcl8xLDE3NjAyNjI0MDA4f3Kq1ZbXy';

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    private FakePanel $panel;

    /**
     * @return iterable<string, array{\Closure(ProviderInterface): mixed, list<Response>, list<string>}> The call, the
     *                                                                                                   panel's answers after the sign-in, and the requests it sends after it
     */
    public static function calls(): iterable
    {
        $waiting = new ClientSpec('amir_1', 1 << 30, Expiry::afterFirstUse(86_400), 2, 42, '42 | amir');
        $fixed = new ClientSpec('amir_1', 0, Expiry::at(new \DateTimeImmutable('2030-01-01 00:00:00 UTC')), comment: '42 | amir', enabled: false);
        $planned = self::user(['status' => 'active', 'on_hold_expire_duration' => null, 'expire' => 1893456000, 'next_plan' => ['data_limit' => 1 << 30, 'expire' => 2_592_000, 'add_remaining_traffic' => true, 'fire_on_either' => false]]);
        $users = self::json(['users' => [self::user(['proxies' => ['trojan' => ['password' => 'Tr0jan-Pass-1', 'flow' => '']]])], 'total' => 1]);

        yield 'testConnection' => [static fn(ProviderInterface $p) => $p->testConnection(), [self::json(['users' => [], 'total' => 0])], ['GET /api/users']];
        yield 'status' => [static fn(ProviderInterface $p) => $p->status(), [self::json(['version' => '0.8.4', 'mem_total' => 1, 'mem_used' => 1, 'cpu_usage' => 1.5]), self::json(['version' => '25.10.31', 'started' => true, 'logs_websocket' => '/api/core/logs'])], ['GET /api/system', 'GET /api/core']];
        yield 'listInbounds' => [static fn(ProviderInterface $p) => $p->listInbounds(), [self::json(self::inbounds())], ['GET /api/inbounds']];
        yield 'servesSubscriptions' => [static fn(ProviderInterface $p) => $p->servesSubscriptions(), [], []];
        yield 'createClient (on hold)' => [static fn(ProviderInterface $p) => $p->createClient(['VLESS TCP REALITY', 'VMess WS'], $waiting), [self::json(self::inbounds()), self::json(self::user())], ['GET /api/inbounds', 'POST /api/user']];
        yield 'createClient (a deadline, switched off)' => [static fn(ProviderInterface $p) => $p->createClient(['Trojan WS'], $fixed), [self::json(self::inbounds()), self::json(self::user()), self::json(self::user(['status' => 'disabled']))], ['GET /api/inbounds', 'POST /api/user', 'PUT /api/user/amir_1']];
        yield 'updateClient (on hold, its next plan sent back)' => [static fn(ProviderInterface $p) => $p->updateClient($waiting), [self::json($planned), self::json($planned)], ['GET /api/user/amir_1', 'PUT /api/user/amir_1']];
        yield 'updateClient (switched off)' => [static fn(ProviderInterface $p) => $p->updateClient($fixed), [self::json(self::user()), self::json(self::user(['status' => 'disabled']))], ['GET /api/user/amir_1', 'PUT /api/user/amir_1']];
        yield 'rotateClientCredentials' => [static fn(ProviderInterface $p) => $p->rotateClientCredentials('amir_1'), [self::json(self::user())], ['POST /api/user/amir_1/revoke_sub']];
        yield 'setClientEnabled (off)' => [static fn(ProviderInterface $p) => $p->setClientEnabled('amir_1', false), [self::json($planned), self::json(self::user(['status' => 'disabled']))], ['GET /api/user/amir_1', 'PUT /api/user/amir_1']];
        yield 'setClientEnabled (on, back on hold)' => [static fn(ProviderInterface $p) => $p->setClientEnabled('amir_1', true), [self::json(self::user(['status' => 'disabled'])), self::json(self::user())], ['GET /api/user/amir_1', 'PUT /api/user/amir_1']];
        yield 'setClientEnabled (on)' => [static fn(ProviderInterface $p) => $p->setClientEnabled('amir_1', true), [self::json(self::user(['status' => 'disabled', 'on_hold_expire_duration' => null, 'expire' => 1893456000])), self::json(self::user())], ['GET /api/user/amir_1', 'PUT /api/user/amir_1']];
        yield 'deleteClient' => [static fn(ProviderInterface $p) => $p->deleteClient('amir_1'), [self::json(['detail' => 'User successfully deleted'])], ['DELETE /api/user/amir_1']];
        yield 'findClient' => [static fn(ProviderInterface $p) => $p->findClient('amir_1', presence: true), [self::json(self::user())], ['GET /api/user/amir_1']];
        yield 'listClients' => [static fn(ProviderInterface $p) => $p->listClients(), [self::json(['username' => 'admin', 'is_sudo' => true]), $users], ['GET /api/admin', 'GET /api/users']];
        yield 'findClientByLink (a link)' => [static fn(ProviderInterface $p) => $p->findClientByLink(self::link('https://sub.example.com/sub/' . self::TOKEN)), [$users, self::json(['username' => 'amir_1']), self::json(self::user())], ['GET /api/users', 'GET /sub/' . self::TOKEN . '/info', 'GET /api/user/amir_1']];
        yield 'findClientByLink (a config)' => [static fn(ProviderInterface $p) => $p->findClientByLink(self::link('trojan://Tr0jan-Pass-1@mz.example:443')), [$users, self::json(self::user())], ['GET /api/users', 'GET /api/user/amir_1']];
        yield 'resetClientTraffic (put back on hold)' => [static fn(ProviderInterface $p) => $p->resetClientTraffic('amir_1'), [self::json(self::user()), self::json(self::user(['status' => 'active'])), self::json(self::user())], ['GET /api/user/amir_1', 'POST /api/user/amir_1/reset', 'PUT /api/user/amir_1']];
        yield 'resetClientTraffic (put back off)' => [static fn(ProviderInterface $p) => $p->resetClientTraffic('amir_1'), [self::json(self::user(['status' => 'disabled'])), self::json(self::user(['status' => 'active'])), self::json(self::user(['status' => 'disabled']))], ['GET /api/user/amir_1', 'POST /api/user/amir_1/reset', 'PUT /api/user/amir_1']];
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

        self::assertSame($expected === [] ? [] : ['POST /api/admin/token', ...$expected], $this->sent(), 'signed in first, then its own calls');
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

    /** The request is an operation of the description, with the query, body and credentials the description gives it. */
    private static function assertDocumented(RequestInterface $request): void
    {
        $method = $request->getMethod();
        self::assertStringStartsWith(self::BASE_PATH . '/', $request->getUri()->getPath(), 'every request goes under the panel\'s address');
        $path = substr($request->getUri()->getPath(), strlen(self::BASE_PATH));

        [$template, $operation] = self::operation($method, $path);
        self::assertNotNull($operation, "{$method} {$path} is not in the panel's description");
        $key = "{$method} {$template}";

        parse_str($request->getUri()->getQuery(), $query);
        $described = array_column(array_filter($operation['parameters'] ?? [], static fn(array $parameter): bool => $parameter['in'] === 'query'), 'name');
        self::assertSame([], array_values(array_diff(array_keys($query), $described)), "{$key} is sent query parameters the panel does not describe");

        // The admin's token where the operation asks for it (OAuth2PasswordBearer), and nowhere else.
        self::assertSame(isset($operation['security']), $request->hasHeader('Authorization'), "{$key}: the admin's token exactly where it is asked for");
        if (isset($operation['security'])) {
            self::assertSame('Bearer jwt-1', $request->getHeaderLine('Authorization'));
        }

        $body = (string) $request->getBody();
        $content = $operation['requestBody']['content'] ?? null;
        if (!is_array($content)) {
            self::assertSame('', $body, "{$key} takes no body");

            return;
        }

        $type = (string) array_key_first($content);
        self::assertStringStartsWith($type, $request->getHeaderLine('Content-Type'), "{$key} takes {$type}");
        if ($type === 'application/json') {
            $sent = json_decode($body, true);
        } else {
            parse_str($body, $sent);
        }
        self::assertIsArray($sent, "{$key} is sent an object");

        $schema = self::resolve($content[$type]['schema']);
        self::assertSame([], array_values(array_diff(array_keys($sent), array_keys($schema['properties'] ?? []))), "{$key} is sent fields the panel does not describe");
        foreach ($schema['required'] ?? [] as $required) {
            self::assertArrayHasKey($required, $sent, "{$key} needs {$required}");
        }
        self::assertNull(self::misfit($sent, $schema), "{$key}: " . self::misfit($sent, $schema));
    }

    /**
     * Why `$value` does not fit `$schema` — the part of JSON Schema the description uses: a reference, a choice of
     * schemas, an enum, a type, a pattern, a minimum, an object's properties, keys and other values, an array's items —;
     * null when it fits.
     *
     * @param array<string, mixed> $schema
     */
    private static function misfit(mixed $value, array $schema, string $at = '$'): ?string
    {
        if (isset($schema['$ref'])) {
            return self::misfit($value, self::resolve($schema), $at);
        }
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $branch) {
                if (self::misfit($value, $branch, $at) === null) {
                    return null;
                }
            }

            return "{$at} fits none of its schemas";
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return "{$at} is none of " . implode(', ', $schema['enum']);
        }

        $type = $schema['type'] ?? null;
        $fits = match ($type) {
            'string' => is_string($value) && (!isset($schema['pattern']) || preg_match('/' . $schema['pattern'] . '/', $value) === 1),
            'integer' => is_int($value) && $value >= ($schema['minimum'] ?? PHP_INT_MIN),
            'number' => (is_int($value) || is_float($value)) && $value >= ($schema['minimum'] ?? PHP_INT_MIN),
            'boolean' => is_bool($value),
            'null' => $value === null,
            // JSON's {} decodes to an empty array.
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            default => true,
        };
        if (!$fits) {
            return "{$at} is no {$type} as described";
        }

        if ($type === 'object' && is_array($value)) {
            foreach ($value as $name => $item) {
                $name = (string) $name;
                if (isset($schema['propertyNames']) && ($reason = self::misfit($name, $schema['propertyNames'], "{$at}[key {$name}]")) !== null) {
                    return $reason;
                }
                $itemSchema = $schema['properties'][$name] ?? (is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : null);
                if ($itemSchema !== null && ($reason = self::misfit($item, $itemSchema, "{$at}.{$name}")) !== null) {
                    return $reason;
                }
            }
        }
        if ($type === 'array' && is_array($value) && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                if (($reason = self::misfit($item, $schema['items'], "{$at}[{$i}]")) !== null) {
                    return $reason;
                }
            }
        }

        return null;
    }

    /**
     * A schema with its reference followed.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function resolve(array $schema): array
    {
        while (isset($schema['$ref'])) {
            $schema = self::spec()['components']['schemas'][substr($schema['$ref'], strlen('#/components/schemas/'))];
        }

        return $schema;
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
        return self::$spec ??= json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/marzban-openapi.json'), true, 512, JSON_THROW_ON_ERROR);
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
        $this->panel->raw(self::json(['access_token' => 'jwt-1', 'token_type' => 'bearer']), ...$answers);
        $connection = new PanelConnection('https://mz.test:8000' . self::BASE_PATH);

        return new MarzbanProvider(new MarzbanClient(new PanelHttp($this->panel->client(), new NullLogger()), $connection, new PanelCredentials(AuthMode::Password, username: 'admin', password: 'pw')), $connection);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function inbounds(): array
    {
        return [
            'vless' => [['tag' => 'VLESS TCP REALITY', 'protocol' => 'vless', 'network' => 'tcp', 'tls' => 'reality', 'port' => 443]],
            'vmess' => [['tag' => 'VMess WS', 'protocol' => 'vmess', 'network' => 'ws', 'tls' => 'none', 'port' => 8080]],
            'trojan' => [['tag' => 'Trojan WS', 'protocol' => 'trojan', 'network' => 'ws', 'tls' => 'tls', 'port' => 8443]],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function user(array $overrides = []): array
    {
        return $overrides + [
            'username' => 'amir_1',
            'status' => 'on_hold',
            'proxies' => ['vless' => ['id' => 'b1c0e3f4-0000-4000-8000-000000000000', 'flow' => '']],
            'inbounds' => ['vless' => ['VLESS TCP REALITY']],
            'expire' => null,
            'data_limit' => 1 << 30,
            'used_traffic' => 0,
            'on_hold_expire_duration' => 86_400,
            'online_at' => null,
            'created_at' => '2026-10-05T10:00:00',
            'subscription_url' => '/sub/dGVzdC10b2tlbi1zaWduZWQx',
        ];
    }

    private static function json(mixed $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    private static function link(string $text): ClientLink
    {
        return ClientLink::find($text) ?? self::fail("No link in {$text}.");
    }
}
