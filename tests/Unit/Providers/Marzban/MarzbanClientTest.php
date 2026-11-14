<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Marzban;

use App\Modules\Providers\Drivers\Marzban\MarzbanApiException;
use App\Modules\Providers\Drivers\Marzban\MarzbanClient;
use App\Modules\Providers\Enums\AuthenticationFailure;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\AuthenticationException;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnexpectedResponseException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;

/**
 * The Marzban transport: an admin's token (kept for the client's life and renewed once), and every way the panel, or
 * something in front of it, can answer, as a typed failure.
 */
final class MarzbanClientTest extends TestCase
{
    private const BASE = 'https://mz.test:8000';

    private FakePanel $panel;

    protected function setUp(): void
    {
        $this->panel = new FakePanel();
    }

    public function testAPasswordIsTradedForATokenThatTheClientKeeps(): void
    {
        $this->panel->raw(self::token('jwt-1'), self::json(200, ['a' => 1]), self::json(200, ['b' => 2]));
        $client = $this->client();

        self::assertSame(['a' => 1], $client->get('/api/system'));
        // The panel is not signed in to — nor its admins told of a login on Telegram — on every call.
        self::assertSame(['b' => 2], $client->get('/api/inbounds'));

        self::assertSame(['POST /api/admin/token', 'GET /api/system', 'GET /api/inbounds'], $this->panel->calls());
        $login = $this->panel->request(0);
        self::assertStringStartsWith('application/x-www-form-urlencoded', $login->getHeaderLine('Content-Type'), 'an OAuth2 password form, not JSON');
        self::assertSame(['grant_type' => 'password', 'username' => 'admin', 'password' => 'S3cret pass'], $this->panel->params(0));
        self::assertSame('', $login->getHeaderLine('Authorization'));
        self::assertSame('Bearer jwt-1', $this->panel->request(1)->getHeaderLine('Authorization'));
        self::assertSame('Bearer jwt-1', $this->panel->request(2)->getHeaderLine('Authorization'));
        self::assertSame('application/json', $this->panel->request(1)->getHeaderLine('Accept'));
    }

    public function testAQueryIsSentAsItIs(): void
    {
        $this->panel->raw(self::token('jwt'), self::json(200, ['users' => [], 'total' => 0]));

        self::assertSame(['users' => [], 'total' => 0], $this->client()->get('/api/users', ['offset' => 0, 'limit' => 500, 'sort' => 'username']));
        self::assertSame('offset=0&limit=500&sort=username', $this->panel->request(1)->getUri()->getQuery());
    }

    public function testAnExpiredTokenIsRenewedOnceAndAFreshOneRefusedIsTheEnd(): void
    {
        $this->panel->raw(
            self::token('old'),
            self::json(200, []),
            self::json(401, ['detail' => 'Could not validate credentials']), // a day later: the panel's JWT expired
            self::token('new'),
            self::json(200, ['fresh' => true]),
        );
        $client = $this->client();
        $client->get('/api/system');

        self::assertSame(['fresh' => true], $client->get('/api/system'));
        self::assertSame('Bearer new', $this->panel->request(4)->getHeaderLine('Authorization'));

        $this->panel->raw(
            self::json(401, ['detail' => 'Could not validate credentials']),
            self::token('newer'),
            self::json(401, ['detail' => 'Could not validate credentials']),
        );
        $this->panel->reset();
        $failure = self::failure(fn() => $client->get('/api/system'));

        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Session, $failure->failure);
        self::assertCount(3, $this->panel->calls(), 'one sign-in, no loop');

        // Nothing is kept from it: the next call signs in again.
        $this->panel->raw(self::token('again'), self::json(200, []));
        $this->panel->reset();
        $client->get('/api/system');
        self::assertSame('POST /api/admin/token', $this->panel->calls()[0]);
    }

    public function testARefusedSignInSaysWhyInThePanelsWords(): void
    {
        $this->panel->raw(self::json(401, ['detail' => 'Incorrect username or password']));

        $failure = self::failure(fn() => $this->client()->get('/api/system'));

        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Login, $failure->failure);
        self::assertSame('Incorrect username or password', $failure->panelMessage);
        self::assertSame(['POST /api/admin/token'], $this->panel->calls());
    }

    public function testASignInAnswerWithoutATokenIsNeverQuoted(): void
    {
        $this->panel->raw(self::json(200, ['token' => 'eyJ-a-credential-in-another-shape']));

        $failure = self::failure(fn() => $this->client()->get('/api/system'));

        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame('', $failure->excerpt);
        self::assertStringNotContainsString('eyJ', $failure->getMessage());
    }

    public function testCredentialsThatAreNotThereAreSaidSoBeforeAnyCall(): void
    {
        $failure = self::failure(fn() => $this->client(password: '')->get('/api/system'));

        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Missing, $failure->failure);
        self::assertSame([], $this->panel->calls());

        // A token is no way into Marzban: a server keeping one but no login is missing its credentials.
        $client = new MarzbanClient(new PanelHttp($this->panel->client(), new NullLogger()), new PanelConnection(self::BASE), new PanelCredentials(AuthMode::Token, token: 'rk_abc'));
        $failure = self::failure(fn() => $client->get('/api/system'));
        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Missing, $failure->failure);
        self::assertSame([], $this->panel->calls());
    }

    public function testOnlyTheUserNotFoundAnswerMeansNoSuchUser(): void
    {
        $this->panel->raw(self::token('jwt'), self::json(404, ['detail' => 'User not found']));
        $client = $this->client();
        self::assertInstanceOf(NotFoundException::class, self::failure(fn() => $client->get('/api/user/amir_1')));

        // A route the panel does not have — a wrong address, the dashboard's — is not a missing user.
        $this->panel->raw(self::json(404, ['detail' => 'Not Found']));
        $failure = self::failure(fn() => $client->get('/api/user/amir_1'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame(404, $failure->httpStatus);
        self::assertStringContainsString('/dashboard', $failure->hint);

        $this->panel->raw(self::json(404, ['detail' => "User doesn't have next plan"]));
        $failure = self::failure(fn() => $client->post('/api/user/amir_1/active-next'));
        self::assertInstanceOf(MarzbanApiException::class, $failure);
        self::assertSame("User doesn't have next plan", $failure->panelMessage);
    }

    public function testAUserOfAnotherAdminIsRefusedWithWhatToDoAboutIt(): void
    {
        $this->panel->raw(self::token('jwt'), self::json(403, ['detail' => "You're not allowed"]));

        $failure = self::failure(fn() => $this->client()->get('/api/user/zed_9'));

        self::assertInstanceOf(MarzbanApiException::class, $failure);
        self::assertSame(403, $failure->httpStatus);
        self::assertSame("You're not allowed", $failure->panelMessage);
        self::assertStringContainsString('sudo', $failure->hint);
        self::assertFalse($failure->unavailable(), 'the panel is fine: this one user is not the admin\'s');
    }

    public function testAnInboundOrProtocolThePanelNoLongerHasIsRefusedWithWhatToDoAboutIt(): void
    {
        $this->panel->raw(
            self::token('jwt'),
            self::json(400, ['detail' => 'Protocol vmess is disabled on your server']),
            self::json(422, ['detail' => ['inbounds' => "Value error, Inbound VMess WS doesn't exist"]]),
            self::json(409, ['detail' => 'User already exists']),
        );
        $client = $this->client();

        foreach (['a protocol switched off in its Xray config' => 400, 'an inbound gone from it' => 422] as $case => $status) {
            $failure = self::failure(fn() => $client->post('/api/user', ['username' => 'amir_1']));
            self::assertInstanceOf(MarzbanApiException::class, $failure, $case);
            self::assertSame($status, $failure->httpStatus, $case);
            self::assertStringContainsString('Core Settings', $failure->hint, $case);
        }

        $failure = self::failure(fn() => $client->post('/api/user', ['username' => 'amir_1']));
        self::assertInstanceOf(MarzbanApiException::class, $failure);
        self::assertSame('', $failure->hint, 'a name taken has nothing to change on the panel');
    }

    public function testThePanelsRefusalsCarryItsDetailInOneLine(): void
    {
        $this->panel->raw(self::token('jwt'), self::json(409, ['detail' => 'User already exists']));
        $client = $this->client();
        $failure = self::failure(fn() => $client->post('/api/user', ['username' => 'amir_1']));
        self::assertInstanceOf(MarzbanApiException::class, $failure);
        self::assertSame(409, $failure->httpStatus);
        self::assertSame('User already exists', $failure->panelMessage);
        self::assertSame('Marzban POST /api/user failed: User already exists', $failure->getMessage());

        $this->panel->raw(self::json(400, ['detail' => 'Protocol vmess is disabled on your server']));
        $failure = self::failure(fn() => $client->post('/api/user', ['username' => 'amir_1']));
        self::assertInstanceOf(MarzbanApiException::class, $failure);
        self::assertSame('Protocol vmess is disabled on your server', $failure->panelMessage);

        // Marzban's own 422: the field → message map its validation handler writes.
        $this->panel->raw(self::json(422, ['detail' => ['status' => 'Value error, User cannot be on hold without a valid on_hold_expire_duration.']]));
        $failure = self::failure(fn() => $client->put('/api/user/a', ['status' => 'on_hold']));
        self::assertInstanceOf(MarzbanApiException::class, $failure);
        self::assertSame('status: Value error, User cannot be on hold without a valid on_hold_expire_duration.', $failure->panelMessage);

        // The framework's default shape, should something answer it.
        $this->panel->raw(self::json(422, ['detail' => [['loc' => ['body', 'username'], 'msg' => 'Field required', 'type' => 'missing']]]));
        $failure = self::failure(fn() => $client->post('/api/user', []));
        self::assertInstanceOf(MarzbanApiException::class, $failure);
        self::assertSame('username: Field required', $failure->panelMessage);
    }

    public function testAnswersThatAreNotTheApiAreUnexpected(): void
    {
        $this->panel->raw(self::token('jwt'), new Response(307, ['Location' => 'https://mz.test:8000/']));
        $client = $this->client();
        $failure = self::failure(fn() => $client->get('/api/users'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertTrue($failure->isRedirect());
        self::assertSame('https://mz.test:8000/', $failure->location);

        // The panel's home page where the API was expected.
        $this->panel->raw(new Response(200, ['Content-Type' => 'text/html'], '<html><body><h1>Marzban</h1></body></html>'));
        $failure = self::failure(fn() => $client->get('/api/users'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame('Marzban', $failure->excerpt);
        self::assertNotSame('', $failure->hint);

        // The panel's own crash: the framework answers plain text — a write may have been done before it.
        $this->panel->raw(new Response(500, ['Content-Type' => 'text/plain'], 'Internal Server Error'));
        $failure = self::failure(fn() => $client->post('/api/user', ['username' => 'amir_1']));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame(500, $failure->httpStatus);
        self::assertTrue($failure->mayHaveBeenDone());
    }

    public function testNothingAnsweringIsAConnectionFailure(): void
    {
        $this->panel->refuse(new ConnectException('cURL error 7: Failed to connect to mz.test port 8000: Connection refused', new Request('POST', 'x')));

        $failure = self::failure(fn() => $this->client()->get('/api/users'));

        self::assertInstanceOf(ConnectionException::class, $failure);
        self::assertSame(ConnectionFailure::Refused, $failure->failure);
    }

    public function testTheServersTimeoutAndTlsChoiceTuneEveryRequestAndRedirectsAreNotFollowed(): void
    {
        $this->panel->raw(self::token('jwt'), self::json(200, ['detail' => 'User successfully deleted']));
        $client = new MarzbanClient(new PanelHttp($this->panel->client(), new NullLogger()), new PanelConnection(self::BASE, verifyTls: false, timeout: 7), new PanelCredentials(AuthMode::Password, username: 'admin', password: 'pw'));

        $client->delete('/api/user/amir_1');

        foreach ([0, 1] as $call) {
            $options = $this->panel->options($call);
            self::assertFalse($options['verify']);
            self::assertSame(7.0, $options['timeout']);
            self::assertFalse($options['allow_redirects']);
            self::assertFalse($options['http_errors']);
        }
    }

    public function testThePublicSubscriptionRouteIsAskedAsAClientAppAsksAndOnlyAJsonObjectIsAnAnswer(): void
    {
        $path = '/sub/YW1pcl8xLDE3NjAyNjI0MDA0ZjNhYmMxMjM0/info';
        $this->panel->raw(
            self::json(200, ['username' => 'amir_1']),
            self::json(404, ['detail' => 'Not Found']),
            self::json(500, ['detail' => 'Internal Server Error']),
            new Response(302, ['Location' => self::BASE . '/dashboard/']),
            new Response(200, ['Content-Type' => 'text/html'], '<html><body>Marzban</body></html>'),
            self::json(200, 'a string'),
        );
        $client = $this->client();

        self::assertSame(['username' => 'amir_1'], $client->anonymous($path));
        foreach (['not found (a token it did not sign, or revoked)', 'a server error', 'a redirect', 'a web page', 'JSON that is no object'] as $case) {
            self::assertNull($client->anonymous($path), "{$case}: no answer, and no failure");
        }

        self::assertCount(6, $this->panel->calls(), 'nobody signed in for it');
        foreach ($this->panel->history as $i => $call) {
            self::assertSame("GET {$path}", "{$call['method']} {$call['path']}", "call {$i}");
            self::assertFalse($call['request']->hasHeader('Authorization'), "call {$i}: no admin's token");
        }
    }

    private function client(string $password = 'S3cret pass'): MarzbanClient
    {
        return new MarzbanClient(new PanelHttp($this->panel->client(), new NullLogger()), new PanelConnection(self::BASE), new PanelCredentials(AuthMode::Password, username: 'admin', password: $password));
    }

    private static function token(string $jwt): Response
    {
        return self::json(200, ['access_token' => $jwt, 'token_type' => 'bearer']);
    }

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body === null ? '' : (string) json_encode($body));
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
