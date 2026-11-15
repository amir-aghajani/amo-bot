<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Remnawave;

use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\Drivers\Remnawave\RemnawaveClient;
use App\Modules\Providers\Drivers\Remnawave\RemnawaveProvider;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;

/**
 * Every request the Remnawave connector sends — each ProviderInterface method's — is an operation of Remnawave's own
 * description (tests/Fixtures/remnawave-openapi.json, the official 3.4.11 one, trimmed to those operations): that method
 * and path, a path's numeric id where it takes one, only documented query parameters within their bounds, the token as
 * the bearer on every operation it secures, and a body only where one is taken — held to its schema: no field it does not
 * describe, none it requires left out, every value of the type, pattern and range it says — and to the refinements its
 * zod schemas add (x-refinements, x-body-optional). The panel's answers in each case are held to the description too, so
 * the connector is tried on what Remnawave really writes. A reflection guard keeps a case per ProviderInterface method.
 */
final class RemnawaveContractTest extends TestCase
{
    /** A reverse proxy's path in front of the panel: every request goes under it. */
    private const BASE_PATH = '/rw';

    private const TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1dWlkIjoiNWIwYzdlMmEtOTFkNC00YzNmLThlNmEtMmY0YjlkMWM3YTMwIiwidXNlcm5hbWUiOm51bGwsInJvbGUiOiJBUEkiLCJpYXQiOjE3NjAwMDAwMDAsImV4cCI6MTc5MTUzNjAwMH0.a0AfkgXIXEKU9nW9aNb6MgPc0Eo7xjqCdzBkUlGGLhI';
    private const NOW = '2026-11-15 10:00:00';
    private const GIB = 1024 ** 3;
    private const DE = 'a1b2c3d4-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
    private const NL = 'b2c3d4e5-6f7a-4b8c-9dae-1f2a3b4c5d6e';
    private const SHORT = 'Xk3pQ9vLm2Rt7Wz5';
    private const VLESS = '3c5e7f9a-1b2d-4e6f-8a0b-2c4d6e8f0a1b';

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    private FakePanel $panel;

    protected function setUp(): void
    {
        Carbon::setTestNow(new Carbon(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    /**
     * @return iterable<string, array{\Closure(ProviderInterface): mixed, list<Response>, list<string>}> The call, the
     *                                                                                                   panel's answers, and the requests it sends
     */
    public static function calls(): iterable
    {
        $dated = new ClientSpec('amir_1', 30 * self::GIB, Expiry::at(new \DateTimeImmutable('2030-01-01 00:00:00 UTC')), 2, 42, '42 | amir');
        $waiting = new ClientSpec('amir_1', 1 << 30, Expiry::afterFirstUse(2_592_000), comment: '42 | amir', enabled: false);
        $endless = new ClientSpec('amir_1');
        $page = self::answer(['users' => [self::user(), self::user(['id' => 8, 'username' => 'sara_2', 'shortUuid' => 'Nw8RtY2pLk5Vb3Xz', 'vlessUuid' => '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d'])], 'total' => 2]);
        $missing = self::json(404, ['timestamp' => '2026-11-15T10:00:00.000Z', 'path' => '/api/users/by-username/amir_1', 'message' => 'User with specified params not found', 'errorCode' => 'A063']);

        yield 'testConnection' => [static fn(ProviderInterface $p) => $p->testConnection(), [$page], ['GET /api/users']];
        yield 'status' => [static fn(ProviderInterface $p) => $p->status(), [self::answer(self::stats())], ['GET /api/system/stats']];
        yield 'listInbounds' => [static fn(ProviderInterface $p) => $p->listInbounds(), [self::answer(['total' => 2, 'internalSquads' => [self::squad(self::DE, 'Germany', 2), self::squad(self::NL, 'Netherlands', 0)]])], ['GET /api/internal-squads']];
        yield 'servesSubscriptions' => [static fn(ProviderInterface $p) => $p->servesSubscriptions(), [], []];
        yield 'createClient (a deadline, two squads)' => [static fn(ProviderInterface $p) => $p->createClient([self::DE, self::NL], $dated), [self::answer(self::user(['expireAt' => '2030-01-01T00:00:00.000Z']), 201)], ['POST /api/users']];
        yield 'createClient (a term to start, switched off)' => [static fn(ProviderInterface $p) => $p->createClient([self::DE], $waiting), [self::answer(self::user(['status' => 'DISABLED']), 201)], ['POST /api/users']];
        yield 'createClient (no end)' => [static fn(ProviderInterface $p) => $p->createClient([self::DE], $endless), [self::answer(self::user(['expireAt' => '2099-12-31T00:00:00.000Z']), 201)], ['POST /api/users']];
        yield 'updateClient (expired, renewed)' => [static fn(ProviderInterface $p) => $p->updateClient($dated), [self::answer(self::user(['status' => 'EXPIRED', 'expireAt' => '2026-11-14T10:00:00.000Z'])), self::answer(self::user())], ['GET /api/users/by-username/amir_1', 'PATCH /api/users']];
        yield 'updateClient (limited, kept off)' => [static fn(ProviderInterface $p) => $p->updateClient($waiting), [self::answer(self::user(['status' => 'LIMITED'])), self::answer(self::user(['status' => 'DISABLED'])), self::answer(self::user(['status' => 'DISABLED']))], ['GET /api/users/by-username/amir_1', 'POST /api/users/7/actions/disable', 'PATCH /api/users']];
        yield 'updateClient (a deadline past, cut to the soonest)' => [static fn(ProviderInterface $p) => $p->updateClient(new ClientSpec('amir_1', 0, Expiry::at(new \DateTimeImmutable('2026-11-01 00:00:00 UTC')))), [self::answer(self::user()), self::answer(self::user(['expireAt' => '2026-11-15T10:01:00.000Z']))], ['GET /api/users/by-username/amir_1', 'PATCH /api/users']];
        yield 'updateClient (no end)' => [static fn(ProviderInterface $p) => $p->updateClient($endless), [self::answer(self::user(['status' => 'DISABLED'])), self::answer(self::user(['expireAt' => '2099-12-31T00:00:00.000Z']))], ['GET /api/users/by-username/amir_1', 'PATCH /api/users']];
        yield 'rotateClientCredentials' => [static fn(ProviderInterface $p) => $p->rotateClientCredentials('amir_1'), [self::answer(self::user()), self::answer(self::user(['shortUuid' => 'Nw8RtY2pLk5Vb3Xz']))], ['GET /api/users/by-username/amir_1', 'POST /api/users/7/actions/revoke']];
        yield 'setClientEnabled (off)' => [static fn(ProviderInterface $p) => $p->setClientEnabled('amir_1', false), [self::answer(self::user(['status' => 'EXPIRED'])), self::answer(self::user(['status' => 'DISABLED']))], ['GET /api/users/by-username/amir_1', 'POST /api/users/7/actions/disable']];
        yield 'setClientEnabled (on, switched on meanwhile)' => [static fn(ProviderInterface $p) => $p->setClientEnabled('amir_1', true), [self::answer(self::user(['status' => 'DISABLED'])), self::json(400, ['timestamp' => '2026-11-15T10:00:00.000Z', 'path' => '/api/users/7/actions/enable', 'message' => 'User already enabled', 'errorCode' => 'A030'])], ['GET /api/users/by-username/amir_1', 'POST /api/users/7/actions/enable']];
        yield 'deleteClient' => [static fn(ProviderInterface $p) => $p->deleteClient('amir_1'), [self::answer(self::user()), new Response(204)], ['GET /api/users/by-username/amir_1', 'DELETE /api/users/7']];
        yield 'findClient' => [static fn(ProviderInterface $p) => $p->findClient('amir_1', presence: true), [self::answer(self::user())], ['GET /api/users/by-username/amir_1']];
        yield 'findClient (none)' => [static fn(ProviderInterface $p) => $p->findClient('amir_1'), [$missing], ['GET /api/users/by-username/amir_1']];
        yield 'listClients' => [static fn(ProviderInterface $p) => $p->listClients(), [$page], ['GET /api/users']];
        yield 'findClientByLink (a link)' => [static fn(ProviderInterface $p) => $p->findClientByLink(self::link('https://sub.example.com/' . self::SHORT)), [self::answer(self::user())], ['GET /api/users/by-short-uuid/' . self::SHORT]];
        yield 'findClientByLink (a config)' => [static fn(ProviderInterface $p) => $p->findClientByLink(self::link('vless://' . self::VLESS . '@de1.example.com:443?security=reality#amir_1')), [$page, self::answer(self::user())], ['GET /api/users', 'GET /api/users/by-username/amir_1']];
        yield 'resetClientTraffic' => [static fn(ProviderInterface $p) => $p->resetClientTraffic('amir_1'), [self::answer(self::user(['status' => 'LIMITED'])), self::answer(self::user())], ['GET /api/users/by-username/amir_1', 'POST /api/users/7/actions/reset-traffic']];
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
        foreach ($this->panel->history as $i => $entry) {
            $key = self::assertDocumented($entry['request']);
            self::assertAnswerDescribed($key, $answers[$i]);
        }
    }

    public function testEveryProviderMethodHasACase(): void
    {
        $covered = array_unique(array_map(static fn(string $case): string => (string) strtok($case, ' '), array_keys(iterator_to_array(self::calls()))));
        $methods = array_map(static fn(\ReflectionMethod $method): string => $method->getName(), (new \ReflectionClass(ProviderInterface::class))->getMethods());

        self::assertEqualsCanonicalizing($methods, array_values($covered), 'a ProviderInterface method without a contract case, or a case for one that is gone');
    }

    /** The fixture keeps the operations the connector calls, and those alone. */
    public function testTheDescriptionIsTrimmedToTheOperationsTheConnectorCalls(): void
    {
        $called = [];
        foreach (self::calls() as [$call, $answers]) {
            $call($this->provider($answers));
            foreach ($this->panel->history as $entry) {
                [$template] = self::operation($entry['method'], substr($entry['path'], strlen(self::BASE_PATH)));
                $called[] = "{$entry['method']} {$template}";
            }
        }

        $kept = [];
        foreach (self::spec()['paths'] as $template => $operations) {
            foreach (array_keys($operations) as $method) {
                $kept[] = strtoupper($method) . " {$template}";
            }
        }
        self::assertEqualsCanonicalizing($kept, array_values(array_unique($called)));
    }

    /** The fixture is the release's description, trimmed, and says so. */
    public function testTheDescriptionIsRemnawavesOwnTrimmed(): void
    {
        self::assertStringContainsString('3.4.11', (string) (self::spec()['x-trimmed-from'] ?? ''));
        self::assertSame(['Remnawave API v3.4.11', '3.4.11'], [self::spec()['info']['title'], self::spec()['info']['version']]);
        self::assertSame(['scheme' => 'bearer', 'bearerFormat' => 'JWT', 'type' => 'http', 'name' => 'Authorization'], self::spec()['components']['securitySchemes']['Authorization']);
    }

    /**
     * The request is an operation of the description, with the path, query, credentials and body the panel takes.
     *
     * @return string Its "METHOD template"
     */
    private static function assertDocumented(RequestInterface $request): string
    {
        $method = $request->getMethod();
        self::assertStringStartsWith(self::BASE_PATH . '/', $request->getUri()->getPath(), 'every request goes under the panel\'s address');
        $path = substr($request->getUri()->getPath(), strlen(self::BASE_PATH));

        [$template, $operation] = self::operation($method, $path);
        self::assertNotNull($operation, "{$method} {$path} is not in the panel's description");
        $key = "{$method} {$template}";
        /** @var list<array<string, mixed>> $parameters */
        $parameters = $operation['parameters'] ?? [];

        // A path's parameters as described: 3.x takes a user's numeric id (a uuid there is 2.x's, which 3.x refuses).
        preg_match(self::pattern($template), $path, $values);
        foreach (array_filter($parameters, static fn(array $parameter): bool => $parameter['in'] === 'path') as $parameter) {
            $value = rawurldecode((string) ($values[$parameter['name']] ?? ''));
            self::assertNull(self::misfit(self::typed($value, $parameter['schema']), $parameter['schema'], $parameter['name']), "{$key}: its path's {$parameter['name']} «{$value}»");
        }

        parse_str($request->getUri()->getQuery(), $query);
        $described = array_column(array_filter($parameters, static fn(array $parameter): bool => $parameter['in'] === 'query'), null, 'name');
        self::assertSame([], array_values(array_diff(array_keys($query), array_keys($described))), "{$key} is sent query parameters the panel does not describe");
        foreach ($query as $name => $value) {
            $schema = $described[$name]['schema'];
            self::assertNull(self::misfit(self::typed((string) $value, $schema), $schema, (string) $name), "{$key}: ?{$name}={$value}");
        }

        // The token on every operation the description secures; Remnawave describes none open among these.
        self::assertSame([['Authorization' => []]], $operation['security'] ?? null, "{$key} is secured");
        self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'), "{$key}: the token as the bearer");

        $body = (string) $request->getBody();
        if (!isset($operation['requestBody'])) {
            self::assertSame('', $body, "{$key} takes no body");

            return $key;
        }
        if ($body === '' && isset($operation['x-body-optional'])) {
            return $key;
        }

        self::assertStringStartsWith('application/json', $request->getHeaderLine('Content-Type'), "{$key} takes JSON");
        $sent = json_decode($body, true);
        self::assertIsArray($sent, "{$key} is sent an object");
        self::assertNull(self::misfit($sent, $operation['requestBody']['content']['application/json']['schema'], 'body'), "{$key}: " . self::misfit($sent, $operation['requestBody']['content']['application/json']['schema'], 'body'));
        self::assertNull(self::refused($key, $sent), "{$key}: " . self::refused($key, $sent));

        return $key;
    }

    /** The panel's answer in the case is one the description gives for that status — what the connector was tried on. */
    private static function assertAnswerDescribed(string $key, Response $answer): void
    {
        [$method, $template] = explode(' ', $key, 2);
        $status = (string) $answer->getStatusCode();
        $described = self::spec()['paths'][$template][strtolower($method)]['responses'][$status] ?? null;
        self::assertNotNull($described, "{$key} answers no {$status}");

        $schema = $described['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            self::assertSame('', (string) $answer->getBody(), "{$key}: {$status} has no content");

            return;
        }
        $body = json_decode((string) $answer->getBody(), true);
        self::assertNull(self::misfit($body, $schema, 'answer'), "{$key} {$status}: " . self::misfit($body, $schema, 'answer'));
    }

    /**
     * Why Remnawave's zod schema would refuse `$sent` beyond what its description says (x-refinements); null when it takes it.
     *
     * @param array<string, mixed> $sent
     */
    private static function refused(string $key, array $sent): ?string
    {
        if ($key === 'PATCH /api/users' && count(array_intersect_key($sent, ['username' => true, 'id' => true])) !== 1) {
            return 'exactly one of username, id';
        }
        if (in_array($key, ['PATCH /api/users', 'POST /api/users'], true) && isset($sent['expireAt']) && strtotime((string) $sent['expireAt']) <= now()->getTimestamp()) {
            return 'expireAt is not in the future';
        }

        return null;
    }

    /**
     * Why `$value` is not what `$schema` describes — the part of OpenAPI 3.0 this description uses: $ref, oneOf, type,
     * nullable, enum, pattern, lengths, bounds, properties (none it does not describe), required, items —; null when it is.
     *
     * @param array<string, mixed> $schema
     */
    private static function misfit(mixed $value, array $schema, string $at): ?string
    {
        if (isset($schema['$ref'])) {
            return self::misfit($value, self::spec()['components']['schemas'][substr($schema['$ref'], strlen('#/components/schemas/'))], $at);
        }
        if (isset($schema['oneOf'])) {
            $misfits = array_map(static fn(array $branch): ?string => self::misfit($value, $branch, $at), $schema['oneOf']);

            return in_array(null, $misfits, true) ? null : implode(' / ', $misfits);
        }
        if ($value === null) {
            return ($schema['nullable'] ?? false) === true || !isset($schema['type']) ? null : "{$at} is null";
        }

        $type = $schema['type'] ?? null;
        $fits = match ($type) {
            null => true,
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            default => false,
        };
        if (!$fits) {
            return "{$at} is no {$type}: " . json_encode($value);
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return "{$at} " . json_encode($value) . ' is none of ' . json_encode($schema['enum'], JSON_UNESCAPED_SLASHES);
        }
        if (is_string($value)) {
            if (isset($schema['pattern']) && preg_match('~' . str_replace('~', '\~', (string) $schema['pattern']) . '~u', $value) !== 1) {
                return "{$at} " . json_encode($value) . " does not match {$schema['pattern']}";
            }
            if (mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX)) {
                return "{$at} " . json_encode($value) . ' is not of the length described';
            }
        }
        if (is_int($value) || is_float($value)) {
            $minimum = $schema['minimum'] ?? null;
            $exclusive = ($schema['exclusiveMinimum'] ?? false) === true;
            if ($minimum !== null && ($exclusive ? $value <= $minimum : $value < $minimum)) {
                return "{$at} {$value} is not " . ($exclusive ? 'above' : 'at least') . " its minimum {$minimum}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                return "{$at} {$value} is above its maximum {$schema['maximum']}";
            }
        }
        if ($type === 'array') {
            foreach ($value as $i => $item) {
                $misfit = self::misfit($item, $schema['items'] ?? [], "{$at}[{$i}]");
                if ($misfit !== null) {
                    return $misfit;
                }
            }
        }
        if ($type === 'object') {
            foreach ($schema['required'] ?? [] as $field) {
                if (!array_key_exists($field, $value)) {
                    return "{$at}.{$field} is required";
                }
            }
            $others = $schema['additionalProperties'] ?? null;
            foreach ($value as $field => $item) {
                $property = $schema['properties'][$field] ?? (is_array($others) ? $others : ($others === true ? [] : null));
                if ($property === null) {
                    return "{$at}.{$field} is not described";
                }
                $misfit = self::misfit($item, $property, "{$at}.{$field}");
                if ($misfit !== null) {
                    return $misfit;
                }
            }
        }

        return null;
    }

    /**
     * A path's or query's text as the value its schema types it: a number for a number, the text otherwise.
     *
     * @param array<string, mixed> $schema
     */
    private static function typed(string $text, array $schema): mixed
    {
        if (!in_array($schema['type'] ?? null, ['number', 'integer'], true)) {
            return $text;
        }

        return preg_match('/^-?\d+$/', $text) === 1 ? (int) $text : (is_numeric($text) ? (float) $text : $text);
    }

    /** The pattern a path template matches, its parameters named. */
    private static function pattern(string $template): string
    {
        return '#^' . preg_replace('/\\\{([^}]+)\\\}/', '(?<$1>[^/]+)', preg_quote($template, '#')) . '$#';
    }

    /**
     * The described operation `$method $path` is, with the template that matched.
     *
     * @return array{string, array<string, mixed>|null}
     */
    private static function operation(string $method, string $path): array
    {
        foreach (self::spec()['paths'] as $template => $operations) {
            if (preg_match(self::pattern((string) $template), $path) === 1 && isset($operations[strtolower($method)])) {
                return [(string) $template, $operations[strtolower($method)]];
            }
        }

        return [$path, null];
    }

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return self::$spec ??= json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/remnawave-openapi.json'), true, 512, JSON_THROW_ON_ERROR);
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
        $connection = new PanelConnection('https://rw.test' . self::BASE_PATH);

        return new RemnawaveProvider(new RemnawaveClient(new PanelHttp($this->panel->client(), new NullLogger()), $connection, new PanelCredentials(AuthMode::Token, token: self::TOKEN)), $connection);
    }

    /**
     * A user as Remnawave 3.4.11 answers it (UserResponseDto): every field it describes.
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
            'telegramId' => 42,
            'email' => null,
            'description' => '42 | amir',
            'tag' => null,
            'hwidDeviceLimit' => null,
            'externalSquadUuid' => null,
            'trojanPassword' => 'Tr0janPassw0rdAbCdEf12',
            'vlessUuid' => self::VLESS,
            'ssPassword' => 'Sh4d0wP4ssw0rdXyZ0123',
            'lastTriggeredThreshold' => 0,
            'subRevokedAt' => null,
            'lastTrafficResetAt' => null,
            'createdAt' => '2026-11-15T10:00:00.000Z',
            'updatedAt' => '2026-11-15T10:00:00.000Z',
            'subscriptionUrl' => 'https://sub.example.com/' . self::SHORT,
            'activeInternalSquads' => [['uuid' => self::DE, 'name' => 'Germany']],
            'userTraffic' => ['usedTrafficBytes' => 1024, 'lifetimeUsedTrafficBytes' => 4096, 'onlineAt' => '2026-11-15T09:59:50.000Z', 'firstConnectedAt' => '2026-11-15T09:00:00.000Z', 'lastConnectedNodeUuid' => 'f6a7b8c9-0d1e-4f20-8132-5d6e7f8091a2'],
        ];
    }

    /** @return array<string, mixed> An internal squad as GET /api/internal-squads lists it, with `$inbounds` inbounds. */
    private static function squad(string $uuid, string $name, int $inbounds): array
    {
        return [
            'uuid' => $uuid,
            'viewPosition' => 1,
            'name' => $name,
            'tags' => [],
            'info' => ['membersCount' => 12, 'inboundsCount' => $inbounds],
            'inbounds' => array_map(static fn(int $i): array => ['uuid' => 'd4e5f6a7-8b9c-4dae-8f01-3b4c5d6e7f80', 'profileUuid' => 'e5f6a7b8-9c0d-4ebf-9012-4c5d6e7f8091', 'tag' => "VLESS_{$i}", 'type' => 'vless', 'network' => 'tcp', 'security' => 'reality', 'port' => 443, 'rawInbound' => null], $inbounds > 0 ? range(1, $inbounds) : []),
            'createdAt' => '2026-10-01T00:00:00.000Z',
            'updatedAt' => '2026-10-01T00:00:00.000Z',
        ];
    }

    /** @return array<string, mixed> GET /api/system/stats (GetStatsResponseDto). */
    private static function stats(): array
    {
        return [
            'cpu' => ['cores' => 4],
            'memory' => ['total' => 8 * self::GIB, 'free' => 5 * self::GIB, 'used' => 3 * self::GIB],
            'uptime' => 86400,
            'timestamp' => 1794736800000,
            'users' => ['statusCounts' => ['ACTIVE' => 120, 'DISABLED' => 3, 'LIMITED' => 2, 'EXPIRED' => 5], 'totalUsers' => 130],
            'onlineStats' => ['lastDay' => 80, 'lastWeek' => 110, 'neverOnline' => 4, 'onlineNow' => 12],
            'nodes' => ['totalOnline' => 2, 'totalBytesLifetime' => '1099511627776'],
        ];
    }

    private static function answer(mixed $response, int $status = 200): Response
    {
        return self::json($status, ['response' => $response]);
    }

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'], (string) json_encode($body));
    }

    private static function link(string $text): ClientLink
    {
        return ClientLink::find($text) ?? self::fail("No link in {$text}.");
    }
}
