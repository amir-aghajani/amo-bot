<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Rebecca;

use App\Modules\Providers\Drivers\Rebecca\RebeccaApiException;
use App\Modules\Providers\Drivers\Rebecca\RebeccaClient;
use App\Modules\Providers\Enums\AuthenticationFailure;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tests\Support\FakePanel;

/**
 * The Rebecca transport: an admin's `rk_…` API key as the bearer of every call, and every way the panel — its Go
 * handlers' `{"detail": …}` refusals (internal/app/api/server.go writeError) — or something in front of it can answer,
 * as a typed failure; and the public subscription route asked without the key.
 */
final class RebeccaClientTest extends TestCase
{
    private const BASE = 'https://rb.test:8000';
    private const KEY = 'rk_q8Xv2kL0bN7rT4yZ1aS6dF3gH9jK5mP2wE4tY6uI8o_';

    /** A user's signed subscription token: base64url("amir_1,1760262400") and its 10-character signature. */
    private const TOKEN = 'YW1pcl8xLDE3NjAyNjI0MDA8f3Kq1ZbXy';

    private FakePanel $panel;

    /** @var list<string> What the transport logged, message and context together. */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->panel = new FakePanel();
    }

    public function testTheKeyIsTheBearerOfEveryCall(): void
    {
        $this->panel->raw(
            self::json(200, ['users' => [], 'link_templates' => new \stdClass(), 'total' => 0, 'status_breakdown' => new \stdClass()]),
            self::json(201, ['username' => 'amir_1', 'status' => 'active']),
            self::json(200, ['username' => 'amir_1', 'status' => 'disabled']),
            self::json(200, ['username' => 'amir_1', 'status' => 'active']),
            self::json(200, ['username' => 'amir_1', 'status' => 'deleted']),
        );
        $client = $this->client();

        self::assertSame(['users' => [], 'link_templates' => [], 'total' => 0, 'status_breakdown' => []], $client->get('/api/users', ['offset' => 0, 'limit' => 500, 'sort' => 'username']));
        $client->post('/api/user', ['username' => 'amir_1', 'service_id' => 3]);
        $client->put('/api/user/amir_1', ['status' => 'disabled']);
        $client->post('/api/user/amir_1/reset');
        $client->delete('/api/user/amir_1');

        self::assertSame(['GET /api/users', 'POST /api/user', 'PUT /api/user/amir_1', 'POST /api/user/amir_1/reset', 'DELETE /api/user/amir_1'], $this->panel->calls());
        self::assertSame('offset=0&limit=500&sort=username', $this->panel->request(0)->getUri()->getQuery());
        foreach ($this->panel->history as $i => $entry) {
            self::assertSame('Bearer ' . self::KEY, $entry['request']->getHeaderLine('Authorization'), "call #{$i}");
            self::assertSame('application/json', $entry['request']->getHeaderLine('Accept'), "call #{$i}");
            self::assertStringNotContainsString(self::KEY, (string) $entry['request']->getUri(), 'the key in its header alone');
        }
        self::assertSame(['username' => 'amir_1', 'service_id' => 3], $this->panel->params(1));
        self::assertStringStartsWith('application/json', $this->panel->request(1)->getHeaderLine('Content-Type'));
        self::assertSame(['status' => 'disabled'], $this->panel->params(2));
        self::assertSame('', (string) $this->panel->request(3)->getBody(), 'reset takes no body');
        self::assertSame('', (string) $this->panel->request(4)->getBody());
    }

    public function testASuccessWithoutABodyIsAnEmptyOne(): void
    {
        $this->panel->raw(new Response(200, [], ''), new Response(204), self::json(200, []));
        $client = $this->client();

        self::assertSame([], $client->post('/api/user/amir_1/reset'));
        $client->delete('/api/user/amir_1');
        self::assertSame([], $client->get('/api/users/onlines', ['usernames' => 'amir_1']), 'nobody online: Rebecca\'s empty list');
    }

    public function testAKeyThatIsNotThereIsSaidSoBeforeAnyCall(): void
    {
        $client = new RebeccaClient(new PanelHttp($this->panel->client(), $this->logger()), new PanelConnection(self::BASE), new PanelCredentials(AuthMode::Password, username: 'admin', password: 'pw'));

        $failure = self::failure(fn() => $client->get('/api/admin'));

        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Missing, $failure->failure);
        self::assertSame([], $this->panel->calls(), 'an admin\'s password is never sent');
    }

    /** Rebecca answers any refused bearer — unknown, revoked, its admin disabled — alike (middleware.go writeAuthError). */
    public function testARefusedKeyIsTheKeysFailureWithWhereToMakeAnother(): void
    {
        $this->panel->raw(new Response(401, ['Content-Type' => 'application/json', 'WWW-Authenticate' => 'Bearer'], "{\"detail\":\"Could not validate credentials\"}\n"));

        $failure = self::failure(fn() => $this->client()->get('/api/admin'));

        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Token, $failure->failure);
        self::assertSame('Could not validate credentials', $failure->panelMessage);
        self::assertStringContainsString('«حساب من ← کلیدهای API»', $failure->hint);
        self::assertStringContainsString('غیرفعال', $failure->hint, 'a disabled admin takes its keys with it');
        self::assertTrue($failure->unavailable(), 'no call goes through with it');

        $said = ProviderErrorPresenter::explain($failure);
        self::assertStringContainsString('«حساب من ← کلیدهای API»', $said['message'], 'the owner reads the remedy');
        self::assertSame('Could not validate credentials', $said['detail']);
    }

    /** The key travels in its header alone: no failure's words, no hint, no presenter's sentence and no log line carries it. */
    public function testTheKeyReachesNoMessageNorLog(): void
    {
        $answers = [
            new Response(401, ['Content-Type' => 'application/json'], '{"detail":"Could not validate credentials"}'),
            self::json(403, ['detail' => "You're not allowed"]),
            self::json(403, ['detail' => "You're not allowed to delete users."]),
            self::json(404, ['detail' => 'User not found']),
            self::json(404, ['detail' => 'not found']),
            self::json(400, ['detail' => 'Service does not have any active hosts']),
            self::json(502, ['detail' => 'database is locked']),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Rebecca</body></html>'),
            new Response(302, ['Location' => 'https://rb.test:8000/dashboard/']),
        ];
        foreach ($answers as $answer) {
            $this->panel->raw($answer);
            $failure = self::failure(fn() => $this->client()->put('/api/user/amir_1', ['status' => 'disabled']));

            $said = ProviderErrorPresenter::explain($failure);
            foreach ([$failure->getMessage(), (string) json_encode($said), ProviderErrorPresenter::describe($failure), $failure instanceof RebeccaApiException ? $failure->hint . $failure->panelMessage : ''] as $words) {
                self::assertStringNotContainsString(self::KEY, $words, $failure::class);
                self::assertStringNotContainsString('rk_q8X', $words, $failure::class);
            }
        }
        foreach ($this->logged as $line) {
            self::assertStringNotContainsString('rk_q8X', $line);
        }
    }

    /** Only Rebecca's "User not found" (users.go writeUserReadError, mutation_repository_helpers.go existingUserTx) is no such user. */
    public function testOnlyUserNotFoundIsAMissingUser(): void
    {
        $this->panel->raw(self::json(404, ['detail' => 'User not found']));
        $failure = self::failure(fn() => $this->client()->get('/api/user/amir_1'));
        self::assertInstanceOf(NotFoundException::class, $failure);
        self::assertFalse($failure->unavailable());

        // A route the panel does not have: its handlers' "not found", its router's fallback "Not Found" — a wrong address,
        // or a Rebecca before its Go API.
        foreach (['not found', 'Not Found'] as $detail) {
            $this->panel->raw(self::json(404, ['detail' => $detail]));
            $failure = self::failure(fn() => $this->client()->get('/api/users/onlines'));
            self::assertInstanceOf(UnexpectedResponseException::class, $failure, $detail);
            self::assertSame(404, $failure->httpStatus);
            self::assertStringContainsString("\u{2068}/dashboard\u{2069}", $failure->hint);
            self::assertStringContainsString('1.0', $failure->hint);
            self::assertSame('', $failure->excerpt, 'the route asked for is not repeated');
        }

        // A web server's own 404 page, not Rebecca's.
        $this->panel->raw(new Response(404, ['Content-Type' => 'text/html'], '<html><body><h1>404 Not Found</h1>nginx</body></html>'));
        $failure = self::failure(fn() => $this->client()->get('/api/admin'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertStringContainsString('1.0', $failure->hint);

        // Any other 404 is the panel's refusal: a service gone, a next plan it does not have.
        $this->panel->raw(self::json(404, ['detail' => "User doesn't have next plan"]));
        $failure = self::failure(fn() => $this->client()->post('/api/user/amir_1/active-next'));
        self::assertInstanceOf(RebeccaApiException::class, $failure);
        self::assertSame([404, "User doesn't have next plan", ''], [$failure->httpStatus, $failure->panelMessage, $failure->hint]);
    }

    public function testARedirectIsAnotherAddress(): void
    {
        $this->panel->raw(new Response(301, ['Location' => 'https://rb.test:8000/api/admin']));

        $failure = self::failure(fn() => $this->client()->get('/api/admin'));

        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertTrue($failure->isRedirect());
        self::assertSame('https://rb.test:8000/api/admin', $failure->location);
        self::assertStringContainsString('https', $failure->hint);
    }

    public function testAWebPageOrAProxysErrorIsNotTheApi(): void
    {
        // The dashboard's page where the API was expected (the address given with /dashboard, say).
        $this->panel->raw(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><html><head><title>Rebecca</title></head><body><div id="root"></div></body></html>'));
        $failure = self::failure(fn() => $this->client()->get('/api/admin'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame('Rebecca', $failure->excerpt);
        self::assertStringContainsString('داشبورد', $failure->hint);

        // A reverse proxy whose upstream is down: the panel may have taken a write.
        $this->panel->raw(new Response(502, [], ''));
        $failure = self::failure(fn() => $this->client()->post('/api/user', ['username' => 'amir_1']));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame(502, $failure->httpStatus);
        self::assertTrue($failure->mayHaveBeenDone());
    }

    /**
     * 403 "You're not allowed" is another admin's user (mutation_repository_helpers.go ensureCanAccessUser, user_get.go) or a
     * sudo-only route; any other 403 is a permission of the user section its admin lacks (permissions.go).
     */
    public function testAnotherAdminsUserAndAMissingPermissionAreToldApart(): void
    {
        $this->panel->raw(self::json(403, ['detail' => "You're not allowed"]));
        $failure = self::failure(fn() => $this->client()->get('/api/user/someone_else'));
        self::assertInstanceOf(RebeccaApiException::class, $failure);
        self::assertSame([403, "You're not allowed"], [$failure->httpStatus, $failure->panelMessage]);
        self::assertStringContainsString('ادمین دیگری', $failure->hint);
        self::assertStringContainsString('sudo', $failure->hint);
        self::assertFalse($failure->unavailable(), 'the panel works; this one user is another admin\'s');
        self::assertTrue($failure->hidesAnothersClient(), 'its name is taken all the same');

        foreach (["You're not allowed to delete users.", 'Unlimited validity users are not allowed for your role.', "You're not allowed to change user IP limits.", "You're not allowed to create or move users to on-hold.", 'Unlimited data is not allowed.'] as $detail) {
            $this->panel->raw(self::json(403, ['detail' => $detail]));
            $failure = self::failure(fn() => $this->client()->delete('/api/user/amir_1'));
            self::assertInstanceOf(RebeccaApiException::class, $failure, $detail);
            self::assertSame($detail, $failure->panelMessage);
            self::assertStringContainsString('این اجازه را', $failure->hint, $detail);
            self::assertStringContainsString('full access', $failure->hint, $detail);
            self::assertFalse($failure->mayHaveBeenDone(), 'refused: nothing was done');
            self::assertFalse($failure->hidesAnothersClient(), "{$detail}: a permission, no other admin's user");
        }
    }

    /** mutation_repository.go: a service the panel no longer has (404), or one without an active host (400). */
    public function testAServiceGoneOrWithoutHostsSaysToPickAnother(): void
    {
        foreach ([404 => 'Service not found', 400 => 'Service does not have any active hosts'] as $status => $detail) {
            $this->panel->raw(self::json($status, ['detail' => $detail]));
            $failure = self::failure(fn() => $this->client()->post('/api/user', ['username' => 'amir_1', 'service_id' => 9]));
            self::assertInstanceOf(RebeccaApiException::class, $failure, $detail);
            self::assertSame([$status, $detail], [$failure->httpStatus, $failure->panelMessage]);
            self::assertStringContainsString('اینباندهای این سرور را دوباره بخوانید', $failure->hint, $detail);
        }

        // Another refusal names no remedy of its own.
        $this->panel->raw(self::json(400, ['detail' => 'service_id is required. Users must be assigned to a service.']));
        $failure = self::failure(fn() => $this->client()->post('/api/user', []));
        self::assertInstanceOf(RebeccaApiException::class, $failure);
        self::assertSame('', $failure->hint);
    }

    public function testTheRefusalCarriesThePanelsWordsInOneLine(): void
    {
        $this->panel->raw(self::json(409, ['detail' => 'User username already exists']));
        $failure = self::failure(fn() => $this->client()->post('/api/user', ['username' => 'amir_1']));
        self::assertInstanceOf(RebeccaApiException::class, $failure);
        self::assertSame('Rebecca POST /api/user failed: User username already exists', $failure->getMessage());
        self::assertSame([409, ''], [$failure->httpStatus, $failure->hint]);
        self::assertFalse($failure->mayHaveBeenDone(), 'refused: nothing was made');

        // A Marzban-shaped client's validation list, should a proxy in front answer so.
        $this->panel->raw(self::json(422, ['detail' => [['loc' => ['body', 'username'], 'msg' => 'field required'], 'service_id is required']]));
        $failure = self::failure(fn() => $this->client()->post('/api/user', []));
        self::assertInstanceOf(RebeccaApiException::class, $failure);
        self::assertSame('{"loc":["body","username"],"msg":"field required"}; service_id is required', $failure->panelMessage);

        // No words at all: the status.
        $this->panel->raw(self::json(400, ['error' => 'x']));
        $failure = self::failure(fn() => $this->client()->post('/api/user', []));
        self::assertSame('Rebecca POST /api/user failed: HTTP 400', $failure->getMessage());
    }

    /**
     * Rebecca's 502 in its own JSON is an error inside a mutation's transaction, rolled back (mutation_repository.go: every
     * write commits last; service.go retries a transient one): nothing was done.
     */
    public function testThePanelsOwnServerErrorOnAWriteDidNothing(): void
    {
        $this->panel->raw(self::json(502, ['detail' => 'database is locked']));

        $failure = self::failure(fn() => $this->client()->post('/api/user', ['username' => 'amir_1']));

        self::assertInstanceOf(RebeccaApiException::class, $failure);
        self::assertSame([502, 'database is locked'], [$failure->httpStatus, $failure->panelMessage]);
        self::assertFalse($failure->mayHaveBeenDone());
    }

    /** The public subscription route: asked with a link's token and no key; its JSON object, or null for any other answer. */
    public function testTheSubscriptionRouteIsAskedWithoutTheKeyAndAnswersAnObjectOrNothing(): void
    {
        $info = ['user' => ['username' => 'amir_1', 'status' => 'active', 'used_traffic' => 0], 'ov_links' => [], 'wg_links' => []];
        $this->panel->raw(
            self::json(200, $info),
            self::json(404, ['detail' => 'Not Found']),
            new Response(200, ['Content-Type' => 'text/plain'], base64_encode("vless://b1c0e3f4-1234-4abc-8def-0123456789ab@rb.test:443#amir_1\n")),
            self::json(200, ['amir_1']),
            self::json(200, new \stdClass()),
            self::json(403, ['detail' => 'Subscription is disabled']),
            new Response(200, ['Content-Type' => 'application/json'], '{"user":'),
        );
        $client = $this->client();

        self::assertSame($info, $client->anonymous('/sub/' . self::TOKEN . '/info'));
        foreach (['a token it does not know', 'a subscription body', 'a list', 'an empty object', 'a refusal', 'broken JSON'] as $case) {
            self::assertNull($client->anonymous('/sub/' . self::TOKEN . '/info'), $case);
        }

        self::assertCount(7, $this->panel->calls());
        self::assertSame('GET /sub/' . self::TOKEN . '/info', $this->panel->calls()[0]);
        foreach ($this->panel->history as $entry) {
            self::assertFalse($entry['request']->hasHeader('Authorization'), 'the key never goes to a public route');
            self::assertSame('application/json', $entry['request']->getHeaderLine('Accept'));
        }

        // Nothing answering is the panel out of reach all the same.
        $this->panel->refuse(new ConnectException('cURL error 28: Operation timed out after 30000 milliseconds', new Request('GET', 'x')));
        $failure = self::failure(fn() => $client->anonymous('/sub/' . self::TOKEN . '/info'));
        self::assertInstanceOf(ConnectionException::class, $failure);
        self::assertStringNotContainsString(self::TOKEN, $failure->getMessage(), 'a customer\'s token goes into no message');
    }

    public function testNothingAnsweringIsAConnectionFailure(): void
    {
        $this->panel->refuse(new ConnectException('cURL error 7: Failed to connect to rb.test port 8000: Connection refused', new Request('GET', 'x')));

        $failure = self::failure(fn() => $this->client()->get('/api/admin'));

        self::assertInstanceOf(ConnectionException::class, $failure);
        self::assertSame(ConnectionFailure::Refused, $failure->failure);
    }

    public function testTheServersTimeoutAndTlsChoiceTuneEveryRequestAndRedirectsAreNotFollowed(): void
    {
        $this->panel->raw(self::json(200, ['username' => 'amir_1', 'status' => 'deleted']), self::json(200, ['user' => ['username' => 'amir_1']]));
        $connection = new PanelConnection(self::BASE, verifyTls: false, timeout: 7);
        $client = new RebeccaClient(new PanelHttp($this->panel->client(), $this->logger()), $connection, new PanelCredentials(AuthMode::Token, token: self::KEY));

        $client->delete('/api/user/amir_1');
        $client->anonymous('/sub/' . self::TOKEN . '/info');

        foreach ([0, 1] as $i) {
            $options = $this->panel->options($i);
            self::assertFalse($options['verify']);
            self::assertSame(7.0, $options['timeout']);
            self::assertFalse($options['allow_redirects']);
            self::assertFalse($options['http_errors']);
        }
    }

    private function client(): RebeccaClient
    {
        return new RebeccaClient(new PanelHttp($this->panel->client(), $this->logger()), new PanelConnection(self::BASE), new PanelCredentials(AuthMode::Token, token: self::KEY));
    }

    /** A log that keeps every line in `$logged`, its context with it. */
    private function logger(): AbstractLogger
    {
        return new class (function (string $line): void {
            $this->logged[] = $line;
        }) extends AbstractLogger {
            /** @param \Closure(string): void $keep */
            public function __construct(private readonly \Closure $keep) {}

            /** @param array<mixed> $context */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->keep)($message . ' ' . json_encode($context));
            }
        };
    }

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body) . "\n");
    }

    /** @param \Closure(): mixed $call */
    private static function failure(\Closure $call): ProviderException
    {
        try {
            $call();
        } catch (ProviderException $e) {
            return $e;
        }

        self::fail('expected a ProviderException');
    }
}
