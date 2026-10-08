<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\ThreeXui;

use App\Core\Logging\RedactingLineFormatter;
use App\Core\Security\Totp;
use App\Modules\Providers\Drivers\ThreeXui\ThreeXuiApiException;
use App\Modules\Providers\Drivers\ThreeXui\ThreeXuiClient;
use App\Modules\Providers\Enums\AuthenticationFailure;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;

/**
 * The transport to a 3x-ui v3 panel: how it signs in (a token, or a cookie session behind the panel's CSRF guard, with a
 * one-time code when the login asks for one), unwraps the envelope, and turns every way a panel can fail into a typed
 * failure the owner can act on.
 */
final class ThreeXuiClientTest extends TestCase
{
    private const BASE = 'https://panel.test:2053/abc';

    private FakePanel $panel;

    public function testBearerTokenIsSentAndNoLoginHappens(): void
    {
        $client = $this->client([self::ok(['a' => 1])], token: 'tok');

        self::assertSame(['a' => 1], $client->get('/panel/api/server/status'));
        self::assertSame(['GET /abc/panel/api/server/status'], $this->panel->calls());
        self::assertSame('Bearer tok', $this->panel->request(0)->getHeaderLine('Authorization'));
        self::assertSame('application/json', $this->panel->request(0)->getHeaderLine('Accept'));
        // Without this the panel answers a refused credential with a bare 404 (older builds) instead of a clean 401.
        self::assertSame('XMLHttpRequest', $this->panel->request(0)->getHeaderLine('X-Requested-With'));
    }

    public function testRejectedTokenIsAnAuthenticationErrorWithThePanelsRemedy(): void
    {
        // 3x-ui aborts with an empty 401 body for an unknown Bearer token.
        $client = $this->client([new Response(401, [], '')], token: 'bad');

        try {
            $client->get('/panel/api/inbounds/options');
            self::fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertSame(AuthenticationFailure::Token, $e->failure);
            self::assertStringContainsString('HTTP 401', $e->getMessage());
            self::assertStringContainsString('Settings → Security → API Token', $e->hint);
        }
    }

    public function testTokenWithoutTheNeededScopeIsReportedAsSuch(): void
    {
        $client = $this->client([new Response(403, [], '{"success":false,"msg":"this API token is not permitted to access this endpoint"}')], token: 'monitor');

        try {
            $client->get('/panel/api/inbounds/options');
            self::fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertSame(AuthenticationFailure::Scope, $e->failure);
            self::assertSame('this API token is not permitted to access this endpoint', $e->panelMessage);
            self::assertStringContainsString('Admin', $e->hint);
        }
    }

    public function testUnknownRouteRedirectAndHtmlAreUnexpectedResponses(): void
    {
        // A wrong web base path (or a panel before v3): Gin's NoRoute answers an empty 404.
        $client = $this->client([new Response(404, [], '')], token: 'tok');
        try {
            $client->get('/panel/api/inbounds/options');
            self::fail('expected UnexpectedResponseException');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(404, $e->httpStatus);
            self::assertFalse($e->isRedirect());
            self::assertStringContainsString('مسیر پایه وب', $e->hint);
        }

        // http:// entered for an https-only panel, or a missing trailing slash.
        $client = $this->client([new Response(301, ['Location' => 'https://panel.test:2053/abc/'])], token: 'tok');
        try {
            $client->get('/panel/api/inbounds/options');
            self::fail('expected UnexpectedResponseException');
        } catch (UnexpectedResponseException $e) {
            self::assertTrue($e->isRedirect());
            self::assertSame('https://panel.test:2053/abc/', $e->location);
        }

        // A reverse proxy's page instead of the API.
        $client = $this->client([new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<html><body><h1>Welcome to nginx!</h1></body></html>')], token: 'tok');
        try {
            $client->get('/panel/api/inbounds/options');
            self::fail('expected UnexpectedResponseException');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(200, $e->httpStatus);
            self::assertSame('Welcome to nginx!', $e->excerpt);
        }
    }

    public function testTransportFailuresAreClassifiedAndNeverRepeatThePanelsAddress(): void
    {
        $cases = [
            ['cURL error 6: Could not resolve host: panel.test (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://panel.test:2053/abc/panel/api/server/status', ConnectionFailure::Dns, 'cURL error 6: Could not resolve host: panel.test'],
            ['cURL error 7: Failed to connect to panel.test port 2053 after 21 ms: Connection refused', ConnectionFailure::Refused, null],
            ['cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received', ConnectionFailure::Timeout, null],
            ['cURL error 60: SSL certificate problem: self-signed certificate', ConnectionFailure::Tls, null],
            ['Connection refused', ConnectionFailure::Refused, null],
            ['something odd', ConnectionFailure::Other, null],
        ];

        foreach ($cases as [$message, $failure, $detail]) {
            $client = $this->client([new ConnectException($message, new Request('GET', 'x'))], token: 'tok');
            try {
                $client->get('/panel/api/server/status');
                self::fail('expected ConnectionException');
            } catch (ConnectionException $e) {
                self::assertSame($failure, $e->failure, $message);
                self::assertSame($detail ?? $message, $e->detail);
                // The web base path is the panel's only secret besides its credentials: it never reaches a log line.
                self::assertStringNotContainsString('/abc', $e->getMessage());
            }
        }

        self::assertTrue(ConnectionFailure::Refused->beforeRequest(), 'a write refused at the door did not happen');
        self::assertFalse(ConnectionFailure::Timeout->beforeRequest(), 'one that timed out may have');
    }

    /**
     * A transport failure logged as the shop logs one — the exception with its trace and every exception it was chained
     * to — says what went wrong, never the panel's web base path, wherever the transport put the address.
     */
    public function testATransportFailureInTheLogNeverNamesThePanelsBasePath(): void
    {
        $log = new TestHandler();
        $log->setFormatter(new RedactingLineFormatter(null, 'Y-m-d H:i:s', true, true, true));
        $logger = new Logger('test', [$log]);

        foreach ([
            'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://panel.test:2053/abc/panel/api/server/status',
            'Error creating resource: [message] fopen(https://panel.test:2053/abc/panel/api/server/status): Failed to open stream: Connection refused',
        ] as $message) {
            $client = $this->client([new ConnectException($message, new Request('GET', self::BASE . '/panel/api/server/status'))], token: 'tok');
            try {
                $client->get('/panel/api/server/status');
                self::fail('expected ConnectionException');
            } catch (ConnectionException $e) {
                self::assertNull($e->getPrevious(), "the transport's own exception, whose message is the whole address, is let go");
                $logger->error('The panel failed', ['exception' => $e]);
            }
        }

        $lines = array_map(static fn($record): string => (string) $record->formatted, $log->getRecords());
        self::assertCount(2, $lines);
        self::assertStringContainsString('could not be reached (timeout): cURL error 28: Operation timed out', $lines[0], 'what the diagnosis needs');
        self::assertStringContainsString('could not be reached (refused): Error creating resource: [message] fopen([address])', $lines[1]);
        foreach ($lines as $line) {
            self::assertStringNotContainsString('/abc', $line, "the base path is the panel's secret");
        }
    }

    public function testCookieModeSignsInLazilyAndReplaysTheCookieAndTheCsrfToken(): void
    {
        $client = $this->client([
            // GET /csrf-token opens the anonymous session and hands out the token the login must carry.
            new Response(200, ['Set-Cookie' => '3x-ui=anon; Path=/abc/; HttpOnly'], '{"success":true,"obj":"csrf-xyz"}'),
            new Response(200, ['Set-Cookie' => '3x-ui=session-1; Path=/abc/; HttpOnly'], '{"success":true,"msg":"Logged in"}'),
            self::ok(['done' => true]),
            self::ok([]),
        ]);

        $client->post('/panel/api/clients/add', ['client' => ['email' => 'a']]);
        $client->get('/panel/api/clients/list');

        [$csrf, $login, $add, $list] = array_map($this->panel->request(...), [0, 1, 2, 3]);

        self::assertSame('GET /abc/csrf-token', $this->panel->calls()[0]);
        self::assertSame('/abc/login', $login->getUri()->getPath());
        self::assertSame(['username' => 'admin', 'password' => 'pw'], json_decode((string) $login->getBody(), true));
        self::assertStringContainsString('3x-ui=anon', $login->getHeaderLine('Cookie'), 'the login continues the anonymous session');
        self::assertSame('csrf-xyz', $login->getHeaderLine('X-CSRF-Token'), 'the login form is CSRF-protected');

        self::assertStringContainsString('3x-ui=session-1', $add->getHeaderLine('Cookie'));
        self::assertSame('csrf-xyz', $add->getHeaderLine('X-CSRF-Token'));
        self::assertSame('', $list->getHeaderLine('X-CSRF-Token'), 'a GET carries no CSRF token');
        self::assertSame('', $add->getHeaderLine('Authorization'));
        self::assertCount(4, $this->panel->calls(), 'signed in once for both calls');
        self::assertSame('', $csrf->getHeaderLine('X-CSRF-Token'));
    }

    public function testLoginRefusedByTheCsrfGuardIsReportedAsSuch(): void
    {
        // A proxy that drops cookies leaves the panel unable to match the token: Gin aborts with an empty 403.
        $client = $this->client([self::ok('csrf-xyz'), new Response(403, [], '')]);

        try {
            $client->get('/panel/api/server/status');
            self::fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertSame(AuthenticationFailure::Csrf, $e->failure);
        }
    }

    public function testPanelsWithoutTheCsrfEndpointStillSignIn(): void
    {
        $client = $this->client([
            new Response(404, [], ''), // no /csrf-token on this build
            new Response(200, ['Set-Cookie' => '3x-ui=s1; Path=/'], '{"success":true}'),
            self::ok([]),
        ]);

        $client->post('/panel/api/clients/add', ['client' => ['email' => 'a']]);

        self::assertSame('', $this->panel->request(1)->getHeaderLine('X-CSRF-Token'));
        self::assertSame('', $this->panel->request(2)->getHeaderLine('X-CSRF-Token'));
    }

    public function testAnExpiredSessionAnsweredWith401IsRenewedOnceThenGivenUp(): void
    {
        $client = $this->client([
            ...self::loginExchange('s1', 'csrf-1'),
            new Response(401, [], ''), // the session is gone
            ...self::loginExchange('s2', 'csrf-2'),
            self::ok(['fresh' => true]),
        ]);

        self::assertSame(['fresh' => true], $client->get('/panel/api/clients/list'));
        self::assertCount(6, $this->panel->calls());

        $client = $this->client([
            ...self::loginExchange('s1', 'csrf-1'),
            new Response(401, [], ''),
            ...self::loginExchange('s2', 'csrf-2'),
            new Response(401, [], ''),
        ]);
        try {
            $client->get('/panel/api/clients/list');
            self::fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertSame(AuthenticationFailure::Session, $e->failure);
        }
    }

    public function testAnExpiredSessionAnsweredWithARedirectToTheLoginPageIsRenewedToo(): void
    {
        $client = $this->client([
            ...self::loginExchange('s1', 't1'),
            new Response(302, ['Location' => '/abc/'], ''), // a build that ignores the XHR marker
            ...self::loginExchange('s2', 't2'),
            self::ok(['ok' => 1]),
        ]);

        self::assertSame(['ok' => 1], $client->get('/panel/api/clients/list'));
        self::assertCount(6, $this->panel->calls());
        self::assertStringContainsString('3x-ui=s2', $this->panel->request(5)->getHeaderLine('Cookie'));
    }

    public function testARefusedLoginSaysWhenThePanelWantsAOneTimeCode(): void
    {
        $client = $this->client([
            self::ok('csrf-1'),
            new Response(200, [], '{"success":false,"msg":"Invalid two-factor code"}'),
            self::ok(true), // getTwoFactorEnable
        ]);

        try {
            $client->get('/panel/api/server/status');
            self::fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertSame(AuthenticationFailure::TwoFactor, $e->failure);
            self::assertSame('Invalid two-factor code', $e->panelMessage);
            self::assertStringContainsString('توکن API', $e->hint, 'a token needs no one-time code');
        }

        self::assertSame('csrf-1', $this->panel->request(2)->getHeaderLine('X-CSRF-Token'), 'the two-factor question is a POST behind the same guard');
    }

    public function testAWrongPasswordIsALoginFailureEvenWhenThePanelCannotSayAboutTwoFactor(): void
    {
        $client = $this->client([
            self::ok('csrf-1'),
            new Response(200, [], '{"success":false,"msg":"Invalid username or password"}'),
            new Response(404, [], ''), // an older panel without /getTwoFactorEnable
        ]);

        try {
            $client->get('/panel/api/server/status');
            self::fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertSame(AuthenticationFailure::Login, $e->failure);
            self::assertSame('Invalid username or password', $e->panelMessage);
        }
    }

    public function testTheOneTimeCodeIsSentWhenASecretIsKept(): void
    {
        $client = $this->client([self::ok('t'), new Response(200, [], '{"success":true}'), self::ok([])], totp: 'JBSWY3DPEHPK3PXP');

        $client->get('/panel/api/server/status');

        $body = json_decode((string) $this->panel->request(1)->getBody(), true);
        self::assertSame(Totp::code('JBSWY3DPEHPK3PXP'), $body['twoFactorCode']);
        self::assertMatchesRegularExpression('/^\d{6}$/', $body['twoFactorCode']);
    }

    public function testMissingCredentialsFailBeforeAnythingIsSent(): void
    {
        $this->panel = new FakePanel();
        $client = new ThreeXuiClient(new PanelHttp($this->panel->client(), new NullLogger()), new PanelConnection(self::BASE), new PanelCredentials(AuthMode::Password));

        try {
            $client->get('/panel/api/server/status');
            self::fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertSame(AuthenticationFailure::Missing, $e->failure);
        }
        self::assertSame([], $this->panel->calls());
    }

    public function testAPanelRefusalIsATypedExceptionInThePanelsWords(): void
    {
        $client = $this->client([new Response(400, [], '{"success":false,"msg":"Duplicate email: amir_1"}')], token: 'tok');

        try {
            $client->post('/panel/api/clients/add', ['client' => ['email' => 'amir_1']]);
            self::fail('expected ThreeXuiApiException');
        } catch (ThreeXuiApiException $e) {
            self::assertSame('Duplicate email: amir_1', $e->panelMessage);
            self::assertSame(400, $e->httpStatus);
            self::assertStringContainsString('POST /panel/api/clients/add', $e->getMessage());
        }
    }

    public function testABodyThatIsNotJsonIsAnUnexpectedResponse(): void
    {
        $client = $this->client([new Response(502, [], '<html>Bad gateway</html>')], token: 'tok');

        try {
            $client->get('/panel/api/server/status');
            self::fail('expected UnexpectedResponseException');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(502, $e->httpStatus);
            self::assertSame('Bad gateway', $e->excerpt);
            self::assertStringContainsString('HTTP 502', $e->getMessage());
        }
    }

    public function testTheConnectionsTimeoutAndTlsRuleTuneEveryRequest(): void
    {
        $options = (new PanelConnection(self::BASE, verifyTls: false, timeout: 60))->requestOptions();

        self::assertFalse($options['verify'], 'a self-signed panel certificate is accepted when the owner says so');
        self::assertSame(60.0, $options['timeout']);
        self::assertSame(10.0, $options['connect_timeout'], 'a host that does not answer at all is given up on early');
        self::assertSame(5.0, (new PanelConnection(self::BASE, timeout: 5))->requestOptions()['connect_timeout']);
    }

    /**
     * The two answers of a cookie login: GET /csrf-token, then POST /login setting the session cookie.
     *
     * @return list<Response>
     */
    private static function loginExchange(string $session, string $csrf): array
    {
        return [self::ok($csrf), new Response(200, ['Set-Cookie' => "3x-ui={$session}; Path=/"], '{"success":true}')];
    }

    /**
     * A client over the fake panel with the given answers queued: a token, or a cookie login as admin/pw.
     *
     * @param list<Response|\Throwable> $answers
     */
    private function client(array $answers, ?string $token = null, ?string $totp = null): ThreeXuiClient
    {
        $this->panel = new FakePanel();
        foreach ($answers as $answer) {
            $answer instanceof \Throwable ? $this->panel->refuse($answer) : $this->panel->raw($answer);
        }

        $credentials = $token !== null
            ? new PanelCredentials(AuthMode::Token, token: $token)
            : new PanelCredentials(AuthMode::Password, username: 'admin', password: 'pw');

        return new ThreeXuiClient(new PanelHttp($this->panel->client(), new NullLogger()), new PanelConnection(self::BASE), $credentials, $totp);
    }

    private static function ok(mixed $obj): Response
    {
        return FakePanel::success($obj);
    }
}
