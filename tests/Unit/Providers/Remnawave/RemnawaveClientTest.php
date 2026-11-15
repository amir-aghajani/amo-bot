<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Remnawave;

use App\Modules\Providers\Drivers\Remnawave\RemnawaveApiException;
use App\Modules\Providers\Drivers\Remnawave\RemnawaveClient;
use App\Modules\Providers\Enums\AuthenticationFailure;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\PanelApiException;
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
 * The Remnawave transport: an API token as the bearer of every call, what an HTTPS reverse proxy would add on a plain
 * http address on the shop's own network (never across the internet), an auth portal's key in X-Api-Key; every answer's
 * `response` unwrapped, a short id asked for masked in what is quoted back, and every way the panel — its
 * NestJS refusals (src/common/exception, libs/contract/constants/errors) — or something in front of it answers, or drops
 * the request without an answer (src/common/middlewares/proxy-check.middleware.ts), as a typed failure.
 */
final class RemnawaveClientTest extends TestCase
{
    private const BASE = 'https://rw.test';

    /** A made-up API token shaped as Remnawave mints one: an HS256 JWT whose claims say `role: API`. */
    private const TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1dWlkIjoiNWIwYzdlMmEtOTFkNC00YzNmLThlNmEtMmY0YjlkMWM3YTMwIiwidXNlcm5hbWUiOm51bGwsInJvbGUiOiJBUEkiLCJpYXQiOjE3NjAwMDAwMDAsImV4cCI6MTc5MTUzNjAwMH0.a0AfkgXIXEKU9nW9aNb6MgPc0Eo7xjqCdzBkUlGGLhI';

    /** A made-up TinyAuth gate key: `Basic` and base64 of a login. */
    private const GATE = 'Basic YW1vYm90OnRlc3QtZ2F0ZQ==';

    /** A subscription's short id, a customer's secret. */
    private const SHORT = 'Xk3pQ9vLm2Rt7Wz5';

    /** Remnawave's 403 for an admin's sign-in token (src/common/request-auth/request-auth.service.ts ADMIN_CLIENT_TYPE_REQUIRED). */
    private const ADMIN_TOKEN = 'For API requests you must create own API-token in the admin dashboard.';

    private FakePanel $panel;

    /** @var list<string> What the transport logged, message and context together. */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->panel = new FakePanel();
    }

    public function testTheTokenIsTheBearerOfEveryCallAndTheResponseIsUnwrapped(): void
    {
        $this->panel->raw(
            self::answer(200, ['users' => [], 'total' => 0]),
            self::answer(201, ['id' => 7, 'username' => 'amir_1']),
            self::answer(200, ['id' => 7, 'username' => 'amir_1', 'status' => 'DISABLED']),
            self::answer(200, ['id' => 7, 'status' => 'ACTIVE']),
            new Response(204),
        );
        $client = $this->client();

        self::assertSame(['users' => [], 'total' => 0], $client->get('/api/users', ['start' => 0, 'size' => 1000]));
        self::assertSame(['id' => 7, 'username' => 'amir_1'], $client->post('/api/users', ['username' => 'amir_1']));
        $client->patch('/api/users', ['id' => 7, 'status' => 'DISABLED']);
        $client->post('/api/users/7/actions/enable');
        $client->delete('/api/users/7');

        self::assertSame(['GET /api/users', 'POST /api/users', 'PATCH /api/users', 'POST /api/users/7/actions/enable', 'DELETE /api/users/7'], $this->panel->calls());
        self::assertSame('start=0&size=1000', $this->panel->request(0)->getUri()->getQuery());
        foreach ($this->panel->history as $i => $entry) {
            self::assertSame('Bearer ' . self::TOKEN, $entry['request']->getHeaderLine('Authorization'), "call #{$i}");
            self::assertSame('application/json', $entry['request']->getHeaderLine('Accept'), "call #{$i}");
            self::assertFalse($entry['request']->hasHeader('X-Api-Key'), 'no gate, no gate key');
            self::assertFalse($entry['request']->hasHeader('X-Forwarded-For'), 'an https address: its proxy says where the call came from');
            self::assertStringNotContainsString(self::TOKEN, (string) $entry['request']->getUri(), 'the token in its header alone');
        }
        self::assertSame(['username' => 'amir_1'], $this->panel->params(1));
        self::assertStringStartsWith('application/json', $this->panel->request(1)->getHeaderLine('Content-Type'));
        self::assertSame(['id' => 7, 'status' => 'DISABLED'], $this->panel->params(2));
        self::assertSame('', (string) $this->panel->request(3)->getBody(), 'an action takes no body');
        self::assertSame('', (string) $this->panel->request(4)->getBody());
    }

    public function testASuccessWithoutABodyOrWithoutAnObjectIsAnEmptyOne(): void
    {
        $this->panel->raw(new Response(204), new Response(200, [], ''), self::answer(200, true), self::answer(200, null));
        $client = $this->client();

        $client->delete('/api/users/7');
        self::assertSame([], $client->post('/api/users/7/actions/reset-traffic'));
        self::assertSame([], $client->get('/api/users/7'), 'a scalar answer is no object');
        self::assertSame([], $client->get('/api/users/7'));
    }

    public function testATokenThatIsNotThereIsSaidSoBeforeAnyCall(): void
    {
        $client = new RemnawaveClient(new PanelHttp($this->panel->client(), $this->logger()), new PanelConnection(self::BASE), new PanelCredentials(AuthMode::Password, username: 'admin', password: 'pw'), self::GATE);

        $failure = self::failure(fn() => $client->get('/api/users'));

        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Missing, $failure->failure);
        self::assertSame([], $this->panel->calls(), 'an admin\'s password — nor the gate key kept beside it — is never sent');
    }

    /**
     * proxy-check.middleware.ts: no X-Forwarded-For, or X-Forwarded-Proto other than https, and the socket is destroyed. The
     * panel's own port over plain http, on this server or its private network (a loopback or private address, localhost,
     * a Docker service's one-word name), gets what an HTTPS proxy would have added.
     */
    public function testAPlainHttpAddressNearbyCarriesWhatAnHttpsProxyWouldHaveAdded(): void
    {
        foreach (['http://10.0.0.5:3000', 'HTTP://192.168.1.20:3000/rw', 'http://172.20.0.3:3000', 'http://127.0.0.1:3000', 'http://localhost:3000', 'http://panel.localhost:3000', 'http://remnawave:3000', 'http://[::1]:3000', 'http://[fd12:3456::7]:3000', 'http://100.101.102.103:3000', 'http://panel.lan:3000', 'http://rw.internal:3000', 'http://nas.home.arpa:3000'] as $address) {
            $this->panel = new FakePanel();
            $this->panel->raw(self::answer(200, ['users' => [], 'total' => 0]), self::answer(200, ['id' => 7]));
            $client = new RemnawaveClient(new PanelHttp($this->panel->client(), $this->logger()), new PanelConnection($address), new PanelCredentials(AuthMode::Token, token: self::TOKEN));

            $client->get('/api/users', ['start' => 0, 'size' => 1]);
            $client->patch('/api/users', ['id' => 7]);

            foreach ($this->panel->history as $entry) {
                self::assertSame('127.0.0.1', $entry['request']->getHeaderLine('X-Forwarded-For'), $address);
                self::assertSame('https', $entry['request']->getHeaderLine('X-Forwarded-Proto'), $address);
                self::assertSame('Bearer ' . self::TOKEN, $entry['request']->getHeaderLine('Authorization'));
            }
        }
    }

    /**
     * Never across the internet: a public address or a domain over plain http would carry the token and the customers'
     * secrets unencrypted — no forwarded headers, Remnawave drops it, the owner reads why. Over https its proxy sets them.
     */
    public function testAPlainHttpAddressAcrossTheInternetOrAnHttpsOneGetsNoForwardedHeaders(): void
    {
        foreach (['http://203.0.113.7:3000', 'http://8.8.8.8:3000', 'http://100.128.0.1:3000', 'http://panel.lan.example.com:3000', 'http://panel.example.com:3000', 'http://[2606:4700::1111]:3000', 'https://rw.test', 'https://10.0.0.5:3000', 'https://remnawave:3000', 'https://example.com/http://'] as $address) {
            $this->panel = new FakePanel();
            $this->panel->raw(self::answer(200, ['users' => [], 'total' => 0]));
            (new RemnawaveClient(new PanelHttp($this->panel->client(), $this->logger()), new PanelConnection($address), new PanelCredentials(AuthMode::Token, token: self::TOKEN)))->get('/api/users');

            self::assertFalse($this->panel->request(0)->hasHeader('X-Forwarded-For'), $address);
            self::assertFalse($this->panel->request(0)->hasHeader('X-Forwarded-Proto'), $address);
            self::assertSame('Bearer ' . self::TOKEN, $this->panel->request(0)->getHeaderLine('Authorization'), "{$address}: the token all the same");
        }
    }

    /** An auth portal in front of the panel (docs/install/panel-security: Caddy's, TinyAuth) lets an API call through with its key. */
    public function testTheGateKeyGoesAsItIsInXApiKeyOnEveryCall(): void
    {
        $this->panel->raw(self::answer(200, ['users' => [], 'total' => 0]), self::answer(201, ['id' => 7]), self::answer(200, ['id' => 7]));
        $client = $this->client(self::GATE);

        $client->get('/api/users');
        $client->post('/api/users', ['username' => 'amir_1']);
        $client->post('/api/users/7/actions/revoke');

        foreach ($this->panel->history as $i => $entry) {
            self::assertSame(self::GATE, $entry['request']->getHeaderLine('X-Api-Key'), "call #{$i}: verbatim");
            self::assertSame('Bearer ' . self::TOKEN, $entry['request']->getHeaderLine('Authorization'), 'the token besides');
            self::assertStringNotContainsString('YW1vYm90', (string) $entry['request']->getUri());
        }

        $this->panel = new FakePanel();
        $this->panel->raw(self::answer(200, []));
        $this->client('')->get('/api/system/stats');
        self::assertFalse($this->panel->request(0)->hasHeader('X-Api-Key'), 'an empty key is none');
    }

    /**
     * Remnawave answers nothing to a request that came through no HTTPS proxy: cut off (cURL 52 «Empty reply», 56 a reset),
     * or — to an https address on a port of its own — a TLS handshake with its plain port (cURL 35). Such a failure says how
     * Remnawave is reached; any other — a name not found, a port closed, a timeout, the shop's proxy — is the generic one,
     * as the transport said it.
     */
    public function testADroppedRequestIsNothingAnsweringWithHowRemnawaveIsReached(): void
    {
        foreach ([
            'cut off' => [self::BASE, 'cURL error 52: Empty reply from server (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://rw.test/api/users?start=0&size=1', ConnectionFailure::Other, true],
            'reset, on the panel\'s own port' => ['http://10.0.0.5:3000', 'cURL error 56: Recv failure: Connection reset by peer (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for http://10.0.0.5:3000/api/users', ConnectionFailure::Other, true],
            'TLS to its plain port' => ['https://panel.example.com:3000', 'cURL error 35: OpenSSL/3.0.13: error:0A00010B:SSL routines::wrong version number (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://panel.example.com:3000/api/users', ConnectionFailure::Tls, false],
        ] as $case => [$address, $message, $failureKind, $mayBeDone]) {
            $this->panel->refuse(new ConnectException($message, new Request('GET', 'x')));
            $client = new RemnawaveClient(new PanelHttp($this->panel->client(), $this->logger()), new PanelConnection($address), new PanelCredentials(AuthMode::Token, token: self::TOKEN));
            $failure = self::failure(fn() => $client->post('/api/users', ['username' => 'amir_1']));

            self::assertInstanceOf(ConnectionException::class, $failure, $case);
            self::assertSame($failureKind, $failure->failure, $case);
            self::assertStringContainsString('ریورس‌پراکسی', $failure->hint, $case);
            self::assertStringContainsString("\u{2068}HTTPS\u{2069}", $failure->hint, $case);
            self::assertStringContainsString('شبکه خصوصی', $failure->hint, "{$case}: the panel's own port only from its own network");
            self::assertSame($mayBeDone, $failure->mayHaveBeenDone(), "{$case}: cut off after it was sent, or never through the handshake");
            self::assertStringNotContainsString(':3000/api', $failure->detail, 'no address in the detail');

            $said = ProviderErrorPresenter::explain($failure);
            self::assertStringContainsString('ریورس‌پراکسی', $said['message'], "{$case}: the owner reads the remedy");
        }

        foreach ([
            'cURL error 6: Could not resolve host: rw.test' => ConnectionFailure::Dns,
            'cURL error 7: Failed to connect to rw.test port 443 after 3 ms: Connection refused' => ConnectionFailure::Refused,
            'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received' => ConnectionFailure::Timeout,
            'cURL error 97: Can\'t complete SOCKS5 connection to rw.test. (4)' => ConnectionFailure::Proxy,
        ] as $message => $failureKind) {
            $this->panel->refuse(new ConnectException($message, new Request('GET', 'x')));
            $failure = self::failure(fn() => $this->client()->get('/api/users'));

            self::assertInstanceOf(ConnectionException::class, $failure, $message);
            self::assertSame([$failureKind, ''], [$failure->failure, $failure->hint], "{$message}: as the transport said it");
        }
    }

    /**
     * A TLS failure on the usual https port is the certificate's — a real HTTPS proxy's, self-signed or of another name —:
     * the generic words say what to do, Remnawave's way in has nothing to add.
     */
    public function testATlsFailureOnTheUsualHttpsPortIsTheCertificatesAlone(): void
    {
        // A certificate refused on a port of its own is a real proxy's too: only a failed handshake is the plain port's.
        $this->panel->refuse(new ConnectException('cURL error 60: SSL certificate problem: certificate has expired (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://rw.test:8443/api/users', new Request('GET', 'x')));
        $client = new RemnawaveClient(new PanelHttp($this->panel->client(), $this->logger()), new PanelConnection('https://rw.test:8443'), new PanelCredentials(AuthMode::Token, token: self::TOKEN));
        $failure = self::failure(fn() => $client->get('/api/users'));
        self::assertInstanceOf(ConnectionException::class, $failure);
        self::assertSame([ConnectionFailure::Tls, ''], [$failure->failure, $failure->hint], 'a certificate refused on :8443');

        foreach (['https://rw.test', 'https://rw.test:443', 'https://rw.test/rw'] as $address) {
            foreach (['cURL error 60: SSL certificate problem: self-signed certificate', 'cURL error 35: OpenSSL/3.0.13: error:0A000410:SSL routines::sslv3 alert handshake failure'] as $message) {
                $this->panel->refuse(new ConnectException("{$message} (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for {$address}/api/users", new Request('GET', 'x')));
                $client = new RemnawaveClient(new PanelHttp($this->panel->client(), $this->logger()), new PanelConnection($address), new PanelCredentials(AuthMode::Token, token: self::TOKEN));
                $failure = self::failure(fn() => $client->get('/api/users'));

                self::assertInstanceOf(ConnectionException::class, $failure, $address);
                self::assertSame([ConnectionFailure::Tls, ''], [$failure->failure, $failure->hint], "{$address}: {$message}");
                self::assertStringContainsString('گواهی', ProviderErrorPresenter::explain($failure)['message']);
            }
        }
    }

    /** request-auth.service.ts: a missing, invalid or expired token is 401 (Nest's {"message":"Unauthorized","statusCode":401}). */
    public function testARefusedTokenIsTheTokensFailureWithWhereToMakeAnother(): void
    {
        $this->panel->raw(self::nest(401, 'Unauthorized'));

        $failure = self::failure(fn() => $this->client()->get('/api/users'));

        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Token, $failure->failure);
        self::assertSame('Unauthorized', $failure->panelMessage);
        self::assertStringContainsString("«\u{2068}Remnawave Settings\u{2069} ← \u{2068}API Tokens\u{2069}»", $failure->hint);
        self::assertStringContainsString('مدت اعتبار', $failure->hint, 'a token expires');
        self::assertTrue($failure->unavailable());

        $said = ProviderErrorPresenter::explain($failure);
        self::assertStringContainsString("\u{2068}API Tokens\u{2069}", $said['message'], 'the owner reads the remedy');
        self::assertSame('Unauthorized', $said['detail']);
    }

    /**
     * Every 403 is the token's: a scope it lacks or a token deleted («Forbidden resource»), an admin's sign-in token (its own
     * words). Remnawave has one kind of admin: never "another admin's user", never a refusal ProvisioningService could read
     * as a taken name (no PanelApiException).
     */
    public function testEvery403IsTheTokensScopeAndAnAdminsSignInTokenIsToldApart(): void
    {
        $this->panel->raw(self::nest(403, 'Forbidden resource', 'Forbidden'));
        $failure = self::failure(fn() => $this->client()->get('/api/users/by-username/amir_1'));
        self::assertInstanceOf(AuthenticationException::class, $failure, 'no PanelApiException');
        self::assertSame([AuthenticationFailure::Scope, 'Forbidden resource'], [$failure->failure, $failure->panelMessage]);
        self::assertStringContainsString("\u{2068}users:*\u{2069}", $failure->hint, 'the scopes it needs');
        self::assertStringContainsString("\u{2068}internal-squads:read\u{2069}", $failure->hint);
        self::assertStringContainsString("\u{2068}system:read\u{2069}", $failure->hint);
        self::assertStringContainsString('حذف', $failure->hint, 'or deleted');
        self::assertTrue($failure->unavailable());

        $this->panel->raw(self::nest(403, self::ADMIN_TOKEN, 'Forbidden'));
        $failure = self::failure(fn() => $this->client()->post('/api/users', ['username' => 'amir_1']));
        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame([AuthenticationFailure::Scope, self::ADMIN_TOKEN], [$failure->failure, $failure->panelMessage]);
        self::assertStringContainsString('توکن ورود یک ادمین', $failure->hint);
        self::assertStringNotContainsString("\u{2068}system:read\u{2069}", $failure->hint, 'not a scope to add');
        self::assertFalse($failure->mayHaveBeenDone(), 'refused before anything was done');

        // HttpExceptionFilter says «Forbidden» of any 403 it words itself.
        $this->panel->raw(self::json(403, ['timestamp' => '2026-11-15T10:00:00.000Z', 'path' => '/api/users', 'message' => 'Forbidden', 'errorCode' => 'E000']));
        $failure = self::failure(fn() => $this->client()->get('/api/users'));
        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Scope, $failure->failure);
    }

    /** Only Remnawave's own «user not found» (A025 by its id, A063 by a name or short id asked for) is no such user. */
    public function testOnlyRemnawavesUserNotFoundIsAMissingUser(): void
    {
        foreach (['A025' => 'User not found', 'A063' => 'User with specified params not found'] as $code => $message) {
            $this->panel->raw(self::refusal(404, $message, $code, '/api/users/by-username/amir_1'));
            $failure = self::failure(fn() => $this->client()->get('/api/users/by-username/amir_1'));
            self::assertInstanceOf(NotFoundException::class, $failure, $code);
            self::assertFalse($failure->unavailable());
            self::assertStringContainsString($message, $failure->getMessage());
        }

        // A route the panel does not have (Nest's own words): a wrong address, or a Remnawave before 2.1.
        $this->panel->raw(self::nest(404, 'Cannot GET /api/users/by-username/amir_1', 'Not Found'));
        $failure = self::failure(fn() => $this->client()->get('/api/users/by-username/amir_1'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame(404, $failure->httpStatus);
        self::assertSame('', $failure->excerpt, 'the route asked for is not repeated');
        self::assertStringContainsString('2.1', $failure->hint);
        self::assertStringContainsString("\u{2068}https://panel.example.com\u{2069}", $failure->hint);
        self::assertTrue($failure->unavailable());

        // A web server's own 404 page, not Remnawave's.
        $this->panel->raw(new Response(404, ['Content-Type' => 'text/html'], '<html><body><h1>404 Not Found</h1>nginx</body></html>'));
        $failure = self::failure(fn() => $this->client()->get('/api/users'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertStringContainsString('2.1', $failure->hint);

        // Any other 404 in Remnawave's words is the panel's refusal: a squad it does not have.
        $this->panel->raw(self::refusal(404, 'Internal squad not found', 'A118', '/api/users'));
        $failure = self::failure(fn() => $this->client()->post('/api/users', ['username' => 'amir_1']));
        self::assertInstanceOf(RemnawaveApiException::class, $failure);
        self::assertSame([404, 'Internal squad not found', 'A118', ''], [$failure->httpStatus, $failure->panelMessage, $failure->errorCode, $failure->hint]);
        self::assertFalse($failure->mayHaveBeenDone());
    }

    public function testARedirectIsAnotherAddressOrAPortalInFront(): void
    {
        $this->panel->raw(new Response(302, ['Location' => 'https://auth.example.com/login?redirect_uri=https%3A%2F%2Frw.test%2Fapi%2Fusers']));

        $failure = self::failure(fn() => $this->client()->get('/api/users'));

        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertTrue($failure->isRedirect());
        self::assertStringStartsWith('https://auth.example.com/login', $failure->location);
        self::assertStringContainsString("\u{2068}https\u{2069}", $failure->hint);
        self::assertStringContainsString("\u{2068}TinyAuth\u{2069}", $failure->hint, 'a portal that took no gate key, or refused it');
        self::assertStringContainsString('«کلید دروازه»', $failure->hint);
    }

    /** A portal turns a call without its key away with a page of its own — whatever its status —, not Remnawave's JSON. */
    public function testAPortalsPageIsTheGateKeysFailure(): void
    {
        foreach ([401 => '<!doctype html><html><head><title>Tinyauth</title></head><body>Unauthorized</body></html>', 403 => 'Forbidden'] as $status => $page) {
            $this->panel->raw(new Response($status, ['Content-Type' => 'text/html'], $page));
            $failure = self::failure(fn() => $this->client(self::GATE)->get('/api/users'));

            self::assertInstanceOf(UnexpectedResponseException::class, $failure, (string) $status);
            self::assertSame($status, $failure->httpStatus);
            self::assertStringContainsString('«کلید دروازه»', $failure->hint, (string) $status);
            self::assertStringContainsString("\u{2068}Caddy\u{2069}", $failure->hint);
            self::assertStringNotContainsString('YW1vYm90', $failure->getMessage() . $failure->hint . $failure->excerpt);
        }

        // TinyAuth answers a caller that asks for JSON in JSON of its own, with neither Nest's statusCode nor Remnawave's
        // errorCode: still the portal's, never "the token is wrong" — the owner would make a new token in vain.
        foreach ([401, 403] as $status) {
            $this->panel->raw(self::json($status, ['status' => $status, 'message' => 'Unauthorized']));
            $failure = self::failure(fn() => $this->client()->get('/api/users/by-short-uuid/' . self::SHORT));

            self::assertInstanceOf(UnexpectedResponseException::class, $failure, "{$status}: no AuthenticationException");
            self::assertSame($status, $failure->httpStatus);
            self::assertStringContainsString('«کلید دروازه»', $failure->hint, (string) $status);
            self::assertStringContainsString('Unauthorized', $failure->excerpt, 'what the portal said, for the owner');
            self::assertStringNotContainsString(self::SHORT, $failure->getMessage() . $failure->excerpt);
            self::assertStringContainsString('کلید دروازه', ProviderErrorPresenter::explain($failure)['message']);
        }
    }

    /** A proxy whose panel gave it nothing — the panel down, or dropping what came without forwarded headers — answers 502. */
    public function testAProxysEmptyHandedPageIsThePanelDownOrItsForwardedHeadersMissing(): void
    {
        $this->panel->raw(new Response(502, ['Content-Type' => 'text/html'], '<html><head><title>502 Bad Gateway</title></head><body><center><h1>502 Bad Gateway</h1></center><hr><center>nginx</center></body></html>'));
        $failure = self::failure(fn() => $this->client()->post('/api/users', ['username' => 'amir_1']));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame(502, $failure->httpStatus);
        self::assertStringContainsString("\u{2068}X-Forwarded-Proto\u{2069}", $failure->hint);
        self::assertStringContainsString('خاموش', $failure->hint, 'or the panel is down');
        self::assertTrue($failure->mayHaveBeenDone(), 'the panel may have taken the write before the proxy gave up');

        // Another gateway error names no cause of its own.
        $this->panel->raw(new Response(504, ['Content-Type' => 'text/html'], '<html><body>504 Gateway Time-out</body></html>'));
        $failure = self::failure(fn() => $this->client()->get('/api/users'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame([504, ''], [$failure->httpStatus, $failure->hint]);
    }

    public function testAWebPageOrJsonWithoutItsEnvelopeIsNotTheApi(): void
    {
        // The dashboard's page where the API was expected (the address given with a page's path, say).
        $this->panel->raw(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><html><head><title>Remnawave</title></head><body><div id="root"></div></body></html>'));
        $failure = self::failure(fn() => $this->client()->get('/api/users'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame('Remnawave', $failure->excerpt);
        self::assertStringContainsString('صفحه اشتراک', $failure->hint);

        // JSON, but not Remnawave's `{"response": …}`: another panel's API, a subscription page's.
        $this->panel->raw(self::json(200, ['users' => [], 'total' => 0]));
        $failure = self::failure(fn() => $this->client()->get('/api/users'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame(200, $failure->httpStatus);
    }

    /** HttpExceptionFilter's refusal ({timestamp, path, message, errorCode}): its words and its code, the call named for the log. */
    public function testTheRefusalCarriesThePanelsWordsAndCode(): void
    {
        $this->panel->raw(self::refusal(400, 'User username already exists', 'A019', '/api/users'));
        $failure = self::failure(fn() => $this->client()->post('/api/users', ['username' => 'amir_1']));
        self::assertInstanceOf(RemnawaveApiException::class, $failure);
        self::assertSame('Remnawave POST /api/users failed: User username already exists', $failure->getMessage());
        self::assertSame([400, 'A019', ''], [$failure->httpStatus, $failure->errorCode, $failure->hint]);
        self::assertFalse($failure->alreadySo());
        self::assertFalse($failure->mayHaveBeenDone(), 'refused: nothing was made');

        foreach (['A029' => 'User already disabled', 'A030' => 'User already enabled'] as $code => $message) {
            $this->panel->raw(self::refusal(400, $message, $code, '/api/users/7/actions/disable'));
            $failure = self::failure(fn() => $this->client()->post('/api/users/7/actions/disable'));
            self::assertInstanceOf(RemnawaveApiException::class, $failure);
            self::assertTrue($failure->alreadySo(), $code);
        }

        // nestjs-zod's refusal: «Validation failed», then each field with why.
        $this->panel->raw(self::json(400, ['message' => 'Validation failed', 'statusCode' => 400, 'errors' => [
            ['validation' => 'regex', 'code' => 'invalid_string', 'message' => 'Username can only contain letters, numbers, underscores and dashes', 'path' => ['username']],
            ['code' => 'too_small', 'message' => 'Traffic limit must be greater than 0', 'path' => ['trafficLimitBytes']],
        ]]));
        $failure = self::failure(fn() => $this->client()->post('/api/users', ['username' => 'a b']));
        self::assertInstanceOf(RemnawaveApiException::class, $failure);
        self::assertSame('Validation failed: username: Username can only contain letters, numbers, underscores and dashes; trafficLimitBytes: Traffic limit must be greater than 0', $failure->panelMessage);
        self::assertSame(['', ''], [$failure->errorCode, $failure->hint]);

        // A deadline it finds past — a clock behind the panel's makes the soonest one so.
        $this->panel->raw(self::json(400, ['message' => 'Validation failed', 'statusCode' => 400, 'errors' => [['code' => 'custom', 'message' => 'Expiration date cannot be in the past', 'path' => ['expireAt']]]]));
        $failure = self::failure(fn() => $this->client()->patch('/api/users', ['id' => 7, 'expireAt' => '2026-11-15T10:01:00.000Z']));
        self::assertInstanceOf(RemnawaveApiException::class, $failure);
        self::assertSame('Validation failed: expireAt: Expiration date cannot be in the past', $failure->panelMessage);
        self::assertStringContainsString('ساعت', $failure->hint, 'both clocks');

        // No words at all: the status.
        $this->panel->raw(self::json(400, ['statusCode' => 400]));
        $failure = self::failure(fn() => $this->client()->post('/api/users', []));
        self::assertSame('Remnawave POST /api/users failed: HTTP 400', $failure->getMessage());
    }

    /**
     * A 5xx in Remnawave's words to a write may come after the write went through — its create reads the user back and
     * fails so when that read does (A023) —; to a read it is only a failure.
     */
    public function testThePanelsOwnServerErrorOnAWriteMayHaveBeenDone(): void
    {
        foreach (['POST' => fn(RemnawaveClient $c) => $c->post('/api/users', ['username' => 'amir_1']), 'PATCH' => fn(RemnawaveClient $c) => $c->patch('/api/users', ['id' => 7]), 'DELETE' => fn(RemnawaveClient $c) => $c->delete('/api/users/7')] as $method => $call) {
            $this->panel->raw(self::refusal(500, 'User creation successful, but failed to get created user with inbounds.', 'A023', '/api/users'));
            $failure = self::failure(fn() => $call($this->client()));
            self::assertInstanceOf(RemnawaveApiException::class, $failure, $method);
            self::assertSame([500, 'A023'], [$failure->httpStatus, $failure->errorCode]);
            self::assertTrue($failure->mayHaveBeenDone(), "{$method}: the caller asks the panel how it stands");
        }

        $this->panel->raw(self::refusal(500, 'Get all users error', 'A024', '/api/users'));
        $failure = self::failure(fn() => $this->client()->get('/api/users'));
        self::assertInstanceOf(RemnawaveApiException::class, $failure);
        self::assertFalse($failure->mayHaveBeenDone(), 'a read changes nothing');
    }

    /** The token and the gate key travel in their headers alone: no failure's words, no hint, no presenter's sentence and no log line carries them. */
    public function testTheTokenAndTheGateKeyReachNoMessageNorLog(): void
    {
        $answers = [
            self::nest(401, 'Unauthorized'),
            self::nest(403, 'Forbidden resource', 'Forbidden'),
            self::nest(403, self::ADMIN_TOKEN, 'Forbidden'),
            self::refusal(404, 'User not found', 'A025', '/api/users'),
            self::nest(404, 'Cannot PATCH /api/users', 'Not Found'),
            self::refusal(400, 'User username already exists', 'A019', '/api/users'),
            self::refusal(500, 'Update user error', 'A039', '/api/users'),
            new Response(401, ['Content-Type' => 'text/html'], '<html><body>Tinyauth</body></html>'),
            new Response(502, ['Content-Type' => 'text/html'], '<html><body>Bad Gateway</body></html>'),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Remnawave</body></html>'),
            new Response(302, ['Location' => 'https://rw.test/auth/login']),
            new ConnectException('cURL error 52: Empty reply from server for https://rw.test/api/users', new Request('PATCH', 'x')),
        ];
        foreach ($answers as $answer) {
            $answer instanceof Response ? $this->panel->raw($answer) : $this->panel->refuse($answer);
            $failure = self::failure(fn() => $this->client(self::GATE)->patch('/api/users', ['id' => 7, 'status' => 'DISABLED']));

            $said = ProviderErrorPresenter::explain($failure);
            $hint = match (true) {
                $failure instanceof PanelApiException => $failure->hint . $failure->panelMessage,
                $failure instanceof AuthenticationException => $failure->hint . $failure->panelMessage,
                $failure instanceof UnexpectedResponseException, $failure instanceof ConnectionException => $failure->hint,
                default => '',
            };
            foreach ([$failure->getMessage(), (string) json_encode($said), ProviderErrorPresenter::describe($failure), ProviderErrorPresenter::summary($failure), $hint] as $words) {
                self::assertStringNotContainsString(self::TOKEN, $words, $failure::class);
                self::assertStringNotContainsString('eyJ1dWlk', $words, $failure::class);
                self::assertStringNotContainsString('YW1vYm90', $words, $failure::class);
            }
        }
        foreach ($this->logged as $line) {
            self::assertStringNotContainsString('eyJ1dWlk', $line);
            self::assertStringNotContainsString('YW1vYm90', $line);
        }
    }

    /** A subscription's short id is a customer's secret: a lookup carries it in its path, which no failure's words repeat. */
    public function testAShortIdAskedForReachesNoMessage(): void
    {
        $this->panel->raw(
            self::refusal(404, 'User with specified params not found', 'A063', '/api/users/by-short-uuid/' . self::SHORT),
            self::refusal(400, 'Something else', 'A999', '/api/users/by-short-uuid/' . self::SHORT),
        );

        $missing = self::failure(fn() => $this->client()->get('/api/users/by-short-uuid/' . self::SHORT));
        $refused = self::failure(fn() => $this->client()->get('/api/users/by-short-uuid/' . self::SHORT));

        self::assertInstanceOf(NotFoundException::class, $missing);
        self::assertSame('Remnawave GET /api/users/by-short-uuid/…: User with specified params not found', $missing->getMessage());
        self::assertInstanceOf(RemnawaveApiException::class, $refused);
        self::assertSame('Remnawave GET /api/users/by-short-uuid/… failed: Something else', $refused->getMessage());
        foreach ([$missing, $refused] as $failure) {
            self::assertStringNotContainsString(self::SHORT, ProviderErrorPresenter::describe($failure));
        }
        self::assertSame('GET /api/users/by-short-uuid/' . self::SHORT, $this->panel->calls()[0], 'asked as it is');
    }

    /**
     * What else answers may repeat the path asked for — a portal's login redirect carries it back (TinyAuth's does), a web
     * server's page names it —: the short id is masked there too, as it is and URL-encoded.
     */
    public function testAShortIdIsMaskedInARedirectAndInAPageThatRepeatIt(): void
    {
        $this->panel->raw(
            new Response(302, ['Location' => 'https://auth.example.com/login?redirect_uri=https%3A%2F%2Frw.test%2Fapi%2Fusers%2Fby-short-uuid%2F' . self::SHORT]),
            new Response(404, ['Content-Type' => 'text/html'], '<html><body><h1>Not Found</h1>/api/users/by-short-uuid/' . self::SHORT . ' was not found on this server.</body></html>'),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Subscription ' . self::SHORT . '</body></html>'),
        );

        $redirect = self::failure(fn() => $this->client()->get('/api/users/by-short-uuid/' . self::SHORT));
        $page = self::failure(fn() => $this->client()->get('/api/users/by-short-uuid/' . self::SHORT));
        $success = self::failure(fn() => $this->client()->get('/api/users/by-short-uuid/' . self::SHORT));

        self::assertInstanceOf(UnexpectedResponseException::class, $redirect);
        self::assertSame('https://auth.example.com/login?redirect_uri=https%3A%2F%2Frw.test%2Fapi%2Fusers%2Fby-short-uuid%2F…', $redirect->location);
        self::assertInstanceOf(UnexpectedResponseException::class, $page);
        self::assertStringContainsString('/api/users/by-short-uuid/… was not found', $page->excerpt);
        self::assertStringContainsString('2.1', $page->hint, 'a 404 page: the address');
        self::assertInstanceOf(UnexpectedResponseException::class, $success);
        self::assertSame('Subscription …', $success->excerpt);
        foreach ([$redirect, $page, $success] as $failure) {
            foreach ([$failure->getMessage(), ProviderErrorPresenter::describe($failure)] as $words) {
                self::assertStringNotContainsString(self::SHORT, $words);
            }
        }

        // A part with what a path cannot carry as it is goes encoded, and is masked as such and encoded again.
        $this->panel->raw(new Response(302, ['Location' => 'https://auth.example.com/login?rd=%2Fapi%2Fusers%2Fby-short-uuid%2FAb%252BCd-1234']));
        $failure = self::failure(fn() => $this->client()->get('/api/users/by-short-uuid/' . rawurlencode('Ab+Cd-1234')));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame('https://auth.example.com/login?rd=%2Fapi%2Fusers%2Fby-short-uuid%2F…', $failure->location);

        // Another call's answer is quoted as it is.
        $this->panel->raw(new Response(302, ['Location' => 'https://auth.example.com/login?rd=%2Fapi%2Fusers']));
        $failure = self::failure(fn() => $this->client()->get('/api/users'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame('https://auth.example.com/login?rd=%2Fapi%2Fusers', $failure->location);
    }

    public function testTheServersTimeoutAndTlsChoiceTuneEveryRequestAndRedirectsAreNotFollowed(): void
    {
        $this->panel->raw(new Response(204), self::answer(200, ['users' => [], 'total' => 0]));
        $connection = new PanelConnection(self::BASE, verifyTls: false, timeout: 7);
        $client = new RemnawaveClient(new PanelHttp($this->panel->client(), $this->logger()), $connection, new PanelCredentials(AuthMode::Token, token: self::TOKEN));

        $client->delete('/api/users/7');
        $client->get('/api/users');

        foreach ([0, 1] as $i) {
            $options = $this->panel->options($i);
            self::assertFalse($options['verify']);
            self::assertSame(7.0, $options['timeout']);
            self::assertFalse($options['allow_redirects']);
            self::assertFalse($options['http_errors']);
        }
    }

    private function client(?string $gate = null): RemnawaveClient
    {
        return new RemnawaveClient(new PanelHttp($this->panel->client(), $this->logger()), new PanelConnection(self::BASE), new PanelCredentials(AuthMode::Token, token: self::TOKEN), $gate);
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

    /** Remnawave's answer to a call that went through: its `response` envelope. */
    private static function answer(int $status, mixed $response): Response
    {
        return self::json($status, ['response' => $response]);
    }

    /** A refusal in HttpExceptionFilter's words (src/common/exception/http-exception.filter.ts). */
    private static function refusal(int $status, string $message, string $code, string $path): Response
    {
        return self::json($status, ['timestamp' => '2026-11-15T10:00:00.000Z', 'path' => $path, 'message' => $message, 'errorCode' => $code]);
    }

    /** A refusal Nest words itself (a guard's, a route it does not have): {message, error, statusCode}. */
    private static function nest(int $status, string $message, ?string $error = null): Response
    {
        return self::json($status, ['message' => $message] + ($error !== null ? ['error' => $error] : []) + ['statusCode' => $status]);
    }

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'], (string) json_encode($body));
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
