<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\PasarGuard;

use App\Modules\Providers\Drivers\PasarGuard\PasarGuardApiException;
use App\Modules\Providers\Drivers\PasarGuard\PasarGuardClient;
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
 * The PasarGuard transport: an API key or an admin's token, the token kept for the client's life (the registry keeps
 * a server's driver a while) and renewed once, and every way the panel (or something in front of it) can answer, as a
 * typed failure.
 */
final class PasarGuardClientTest extends TestCase
{
    private const BASE = 'https://pg.test:8000';
    private const KEY = 'pg_key_6f1c2a3b-4d5e-4f60-8a1b-2c3d4e5f6a7b';

    private FakePanel $panel;

    protected function setUp(): void
    {
        $this->panel = new FakePanel();
    }

    public function testAnApiKeyIsSentAsItsHeaderAndNobodySignsIn(): void
    {
        $this->panel->raw(self::json(200, ['users' => [], 'total' => 0]));

        self::assertSame(['users' => [], 'total' => 0], $this->keyClient()->get('/api/users', ['limit' => 1]));

        $request = $this->panel->request(0);
        self::assertSame(['GET /api/users'], $this->panel->calls());
        self::assertSame('limit=1', $request->getUri()->getQuery());
        self::assertSame(self::KEY, $request->getHeaderLine('X-Api-Key'));
        self::assertSame('', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testAPasswordIsTradedForATokenThatTheClientKeeps(): void
    {
        $this->panel->raw(self::token('jwt-1'), self::json(200, ['a' => 1]), self::json(200, ['b' => 2]));
        $client = $this->passwordClient();

        self::assertSame(['a' => 1], $client->get('/api/system'));
        // The panel is not signed in to — nor its admins told of a login — on every call.
        self::assertSame(['b' => 2], $client->get('/api/groups'));

        self::assertSame(['POST /api/admin/token', 'GET /api/system', 'GET /api/groups'], $this->panel->calls());
        $login = $this->panel->request(0);
        self::assertStringStartsWith('application/x-www-form-urlencoded', $login->getHeaderLine('Content-Type'), 'an OAuth2 password form, not JSON');
        self::assertSame(['grant_type' => 'password', 'username' => 'admin', 'password' => 'S3cret pass'], $this->panel->params(0));
        self::assertSame('Bearer jwt-1', $this->panel->request(1)->getHeaderLine('Authorization'));
        self::assertSame('Bearer jwt-1', $this->panel->request(2)->getHeaderLine('Authorization'));
    }

    public function testAnExpiredTokenIsRenewedOnceAndAFreshOneRefusedIsTheEnd(): void
    {
        $this->panel->raw(
            self::token('old'),
            self::json(200, []),
            self::json(401, ['detail' => 'Could not validate credentials']), // a day later
            self::token('new'),
            self::json(200, ['fresh' => true]),
        );
        $client = $this->passwordClient();
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

    public function testRefusedSignInsSayWhy(): void
    {
        $this->panel->raw(self::json(401, ['detail' => 'Incorrect username or password']));
        $failure = self::failure(fn() => $this->passwordClient()->get('/api/system'));
        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Login, $failure->failure);
        self::assertSame('Incorrect username or password', $failure->panelMessage);
        self::assertSame('', $failure->hint);

        $this->panel->raw(self::json(403, ['detail' => 'your account has been disabled']));
        $failure = self::failure(fn() => $this->passwordClient()->get('/api/system'));
        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertStringContainsString('Admins', $failure->hint);

        $this->panel->raw(self::json(400, ['detail' => 'env admin not allowed in production']));
        $failure = self::failure(fn() => $this->passwordClient()->get('/api/system'));
        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertStringContainsString('SUDO_USERNAME', $failure->hint);
    }

    public function testASignInAnswerWithoutATokenIsNeverQuoted(): void
    {
        $this->panel->raw(self::json(200, ['token' => 'eyJ-a-credential-in-another-shape']));

        $failure = self::failure(fn() => $this->passwordClient()->get('/api/system'));

        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame('', $failure->excerpt);
        self::assertStringNotContainsString('eyJ', $failure->getMessage());
    }

    public function testCredentialsThatAreNotThereAreSaidSoBeforeAnyCall(): void
    {
        $failure = self::failure(fn() => $this->passwordClient(password: '')->get('/api/system'));

        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Missing, $failure->failure);
        self::assertSame([], $this->panel->calls());
    }

    public function testARefusedApiKeyAndAKeyWithoutTheRightsAreAuthenticationFailures(): void
    {
        $this->panel->raw(self::json(401, ['detail' => 'Could not validate credentials']));
        $failure = self::failure(fn() => $this->keyClient()->get('/api/users'));
        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Token, $failure->failure);
        self::assertStringContainsString('API Keys', $failure->hint);
        self::assertStringContainsString('5.1', $failure->hint, 'older panels know no API keys');

        $this->panel->raw(self::json(403, ['detail' => 'Permission denied: users.create']));
        $failure = self::failure(fn() => $this->keyClient()->post('/api/user', ['username' => 'a']));
        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Scope, $failure->failure);
        self::assertSame('Permission denied: users.create', $failure->panelMessage);
    }

    public function testAnAdminWithoutTheRightsGetsThePanelsReason(): void
    {
        $this->panel->raw(self::token('jwt'), self::json(403, ['detail' => 'Permission denied: groups.read']));

        $failure = self::failure(fn() => $this->passwordClient()->get('/api/groups'));

        self::assertInstanceOf(PasarGuardApiException::class, $failure);
        self::assertSame(403, $failure->httpStatus);
        self::assertSame('Permission denied: groups.read', $failure->panelMessage);
    }

    public function testOnlyTheUserNotFoundAnswerMeansNoSuchUser(): void
    {
        $this->panel->raw(self::json(404, ['detail' => 'User not found']));
        self::assertInstanceOf(NotFoundException::class, self::failure(fn() => $this->keyClient()->get('/api/user/by-username/amir_1')));

        // A route the panel does not have — a wrong address, or a panel older than 3.1 — is not a missing user.
        $this->panel->raw(self::json(404, ['detail' => 'Not Found']));
        $failure = self::failure(fn() => $this->keyClient()->get('/api/user/by-username/amir_1'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame(404, $failure->httpStatus);
        self::assertStringContainsString('/dashboard', $failure->hint);

        $this->panel->raw(self::json(404, ['detail' => 'Group not found']));
        $failure = self::failure(fn() => $this->keyClient()->post('/api/user', ['group_ids' => [9]]));
        self::assertInstanceOf(PasarGuardApiException::class, $failure);
        self::assertSame('Group not found', $failure->panelMessage);
    }

    public function testThePanelsRefusalsCarryItsDetailInOneLine(): void
    {
        $this->panel->raw(self::json(409, ['detail' => 'User already exists']));
        $failure = self::failure(fn() => $this->keyClient()->post('/api/user', ['username' => 'amir_1']));
        self::assertInstanceOf(PasarGuardApiException::class, $failure);
        self::assertSame(409, $failure->httpStatus);
        self::assertSame('User already exists', $failure->panelMessage);
        self::assertSame('PasarGuard POST /api/user failed: User already exists', $failure->getMessage());

        // PasarGuard's own 422: the field → message map.
        $this->panel->raw(self::json(422, ['detail' => ['status' => 'Value error, User cannot be on hold without a valid on_hold_expire_duration.']]));
        $failure = self::failure(fn() => $this->keyClient()->put('/api/user/by-username/a', ['status' => 'on_hold']));
        self::assertInstanceOf(PasarGuardApiException::class, $failure);
        self::assertSame('status: Value error, User cannot be on hold without a valid on_hold_expire_duration.', $failure->panelMessage);

        // The framework's default shape, should a route answer it.
        $this->panel->raw(self::json(422, ['detail' => [['loc' => ['body', 'username'], 'msg' => 'Field required', 'type' => 'missing']]]));
        $failure = self::failure(fn() => $this->keyClient()->post('/api/user', []));
        self::assertInstanceOf(PasarGuardApiException::class, $failure);
        self::assertSame('username: Field required', $failure->panelMessage);
    }

    public function testAnswersThatAreNotTheApiAreUnexpected(): void
    {
        $this->panel->raw(new Response(307, ['Location' => 'https://pg.test:8000/']));
        $failure = self::failure(fn() => $this->keyClient()->get('/api/users'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertTrue($failure->isRedirect());
        self::assertSame('https://pg.test:8000/', $failure->location);

        // The dashboard's page where the API was expected.
        $this->panel->raw(new Response(200, ['Content-Type' => 'text/html'], '<html><body><div id="root">PasarGuard</div></body></html>'));
        $failure = self::failure(fn() => $this->keyClient()->get('/api/users'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame('PasarGuard', $failure->excerpt);
        self::assertNotSame('', $failure->hint);

        // A reverse proxy whose upstream is down.
        $this->panel->raw(new Response(502, [], ''));
        $failure = self::failure(fn() => $this->keyClient()->get('/api/users'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame(502, $failure->httpStatus);
    }

    public function testNothingAnsweringIsAConnectionFailure(): void
    {
        $this->panel->refuse(new ConnectException('cURL error 7: Failed to connect to pg.test port 8000: Connection refused', new Request('GET', 'x')));

        $failure = self::failure(fn() => $this->keyClient()->get('/api/users'));

        self::assertInstanceOf(ConnectionException::class, $failure);
        self::assertSame(ConnectionFailure::Refused, $failure->failure);
        self::assertSame('cURL error 7: Failed to connect to pg.test port 8000: Connection refused', $failure->detail);
    }

    public function testTheServersTimeoutAndTlsChoiceTuneEveryRequestAndRedirectsAreNotFollowed(): void
    {
        $this->panel->raw(self::json(204, null));
        $client = new PasarGuardClient(new PanelHttp($this->panel->client(), new NullLogger()), new PanelConnection(self::BASE, verifyTls: false, timeout: 7), new PanelCredentials(AuthMode::Token, token: self::KEY));

        $client->delete('/api/user/by-username/amir_1');

        $options = $this->panel->options(0);
        self::assertFalse($options['verify']);
        self::assertSame(7.0, $options['timeout']);
        self::assertSame(7.0, $options['connect_timeout']);
        self::assertFalse($options['allow_redirects']);
        self::assertFalse($options['http_errors']);
    }

    private function keyClient(): PasarGuardClient
    {
        return new PasarGuardClient(new PanelHttp($this->panel->client(), new NullLogger()), new PanelConnection(self::BASE), new PanelCredentials(AuthMode::Token, token: self::KEY));
    }

    private function passwordClient(string $password = 'S3cret pass'): PasarGuardClient
    {
        return new PasarGuardClient(new PanelHttp($this->panel->client(), new NullLogger()), new PanelConnection(self::BASE), new PanelCredentials(AuthMode::Password, username: 'admin', password: $password));
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
