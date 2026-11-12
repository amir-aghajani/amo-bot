<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\HmPanel;

use App\Modules\Providers\Drivers\HmPanel\HmPanelApiException;
use App\Modules\Providers\Drivers\HmPanel\HmPanelClient;
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
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;

/**
 * The HMPanel transport: the API key as the bearer of every call under /api, and every way the panel, or something in
 * front of it, can answer, as a typed failure — its scopes, its client 404 and its rate limit among them.
 */
final class HmPanelClientTest extends TestCase
{
    private const BASE = 'https://hm.test';
    private const KEY = 'hmp_q8Xv2kL0bN7rT4yZ1aS6dF3gH9jK5mP2';

    private FakePanel $panel;

    protected function setUp(): void
    {
        $this->panel = new FakePanel();
    }

    public function testTheKeyIsTheBearerOfEveryCallUnderTheApisPrefix(): void
    {
        $this->panel->raw(self::json(200, ['data' => [], 'page' => 1, 'limit' => 200, 'total' => 0]), self::json(200, ['id' => 'c1']));
        $client = $this->client();

        self::assertSame(['data' => [], 'page' => 1, 'limit' => 200, 'total' => 0], $client->get('/v1/clients/search', ['page' => 1, 'limit' => 200]));
        $client->patch('/v1/clients/c1', ['enable' => false]);

        self::assertSame(['GET /api/v1/clients/search', 'PATCH /api/v1/clients/c1'], $this->panel->calls());
        $request = $this->panel->request(0);
        self::assertSame('page=1&limit=200', $request->getUri()->getQuery());
        self::assertSame('Bearer ' . self::KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('', $request->getHeaderLine('X-Api-Key'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame(['enable' => false], $this->panel->params(1));
        self::assertSame('Bearer ' . self::KEY, $this->panel->request(1)->getHeaderLine('Authorization'));
    }

    public function testASuccessWithoutAnObjectIsAnEmptyOne(): void
    {
        $this->panel->raw(new Response(200, [], ''), self::json(200, true), new Response(204));
        $client = $this->client();

        self::assertSame([], $client->post('/v1/clients/c1/reset-usage'));
        self::assertSame([], $client->get('/v1/clients/c1'));
        $client->delete('/v1/clients/c1');
        self::assertCount(3, $this->panel->calls());
    }

    public function testAKeyThatIsNotThereIsSaidSoBeforeAnyCall(): void
    {
        $client = new HmPanelClient(new PanelHttp($this->panel->client(), new NullLogger()), new PanelConnection(self::BASE), new PanelCredentials(AuthMode::Password, username: 'admin', password: 'pw'));

        $failure = self::failure(fn() => $client->get('/v1/me'));

        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Missing, $failure->failure);
        self::assertSame([], $this->panel->calls());
    }

    public function testARefusedKeyKeepsThePanelsWordsAndSaysWhereToMakeAnother(): void
    {
        $this->panel->raw(self::json(401, ['statusCode' => 401, 'message' => 'Invalid API key', 'error' => 'Unauthorized']));

        $failure = self::failure(fn() => $this->client()->get('/v1/me'));

        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Token, $failure->failure);
        self::assertSame('Invalid API key', $failure->panelMessage);
        self::assertStringContainsString('«تنظیمات ← API»', $failure->hint);
        self::assertStringContainsString('غیرفعال یا منقضی', $failure->hint, 'a disabled or expired admin takes its keys with it');
        self::assertStringNotContainsString(self::KEY, $failure->getMessage());
    }

    public function testAKeyWithoutAScopeNamesTheScope(): void
    {
        $this->panel->raw(self::json(403, ['statusCode' => 403, 'message' => 'Missing scope: clients.renew', 'error' => 'Forbidden']));

        $failure = self::failure(fn() => $this->client()->post('/v1/clients/c1/reset-usage'));

        self::assertInstanceOf(AuthenticationException::class, $failure);
        self::assertSame(AuthenticationFailure::Scope, $failure->failure);
        self::assertSame('Missing scope: clients.renew', $failure->panelMessage);
        self::assertStringStartsWith('کلید دسترسی clients.renew را ندارد؛', $failure->hint);
        self::assertStringContainsString('«تنظیمات ← API»', $failure->hint);
    }

    public function testAnyOther403IsThePanelsRefusal(): void
    {
        $this->panel->raw(self::json(403, ['statusCode' => 403, 'message' => 'Forbidden resource', 'error' => 'Forbidden']));

        $failure = self::failure(fn() => $this->client()->get('/v1/clients/c9'));

        self::assertInstanceOf(HmPanelApiException::class, $failure);
        self::assertSame([403, 'Forbidden resource'], [$failure->httpStatus, $failure->panelMessage]);
        self::assertFalse($failure->unavailable());
    }

    public function testOnlyHmPanelsClientNotFoundMeansNoSuchClient(): void
    {
        $this->panel->raw(self::json(404, ['statusCode' => 404, 'message' => 'Client not found', 'error' => 'Not Found']));
        self::assertInstanceOf(NotFoundException::class, self::failure(fn() => $this->client()->get('/v1/clients/lookup', ['key' => 'amir_1'])));

        // A route the panel does not have — a wrong address, or a panel before 2.5 — is not a missing client.
        $this->panel->raw(self::json(404, ['statusCode' => 404, 'message' => 'Cannot GET /hm/api/v1/me', 'error' => 'Not Found']));
        $failure = self::failure(fn() => $this->client()->get('/v1/me'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame(404, $failure->httpStatus);
        self::assertStringContainsString('2.5', $failure->hint);
        self::assertSame('', $failure->excerpt, 'the route asked for, which holds the panel\'s path, is not repeated');
        self::assertStringNotContainsString('/hm/', $failure->getMessage());

        $this->panel->raw(self::json(404, ['statusCode' => 404, 'message' => 'Admin not found', 'error' => 'Not Found']));
        $failure = self::failure(fn() => $this->client()->get('/v1/account'));
        self::assertInstanceOf(HmPanelApiException::class, $failure);
        self::assertSame('Admin not found', $failure->panelMessage);
    }

    public function testThePanelsRefusalsCarryItsMessageInOneLine(): void
    {
        $this->panel->raw(self::json(400, ['statusCode' => 400, 'message' => 'Email "amir_1" is already in use on a selected panel.', 'error' => 'Bad Request']));
        $failure = self::failure(fn() => $this->client()->post('/v1/clients', ['email' => 'amir_1']));
        self::assertInstanceOf(HmPanelApiException::class, $failure);
        self::assertSame(400, $failure->httpStatus);
        self::assertSame('Email "amir_1" is already in use on a selected panel.', $failure->panelMessage);
        self::assertSame('HMPanel POST /v1/clients failed: Email "amir_1" is already in use on a selected panel.', $failure->getMessage());
        self::assertFalse($failure->mayHaveBeenDone(), 'refused: nothing was made');

        // Nest's validation: a list of messages.
        $this->panel->raw(self::json(400, ['statusCode' => 400, 'message' => ['email must be a string', 'inboundIds must be an array'], 'error' => 'Bad Request']));
        $failure = self::failure(fn() => $this->client()->post('/v1/clients', []));
        self::assertInstanceOf(HmPanelApiException::class, $failure);
        self::assertSame('email must be a string; inboundIds must be an array', $failure->panelMessage);
    }

    /**
     * A 5xx in the panel's own JSON (an error inside HMPanel, past its database write or not) leaves a write maybe done: a
     * client made though the answer failed is not made again under the next name. A read that failed so did nothing.
     */
    public function testAServerErrorOnAWriteMayHaveBeenDone(): void
    {
        $this->panel->raw(self::json(500, ['statusCode' => 500, 'message' => 'Internal server error']));
        $failure = self::failure(fn() => $this->client()->post('/v1/clients', ['email' => 'amir_1']));
        self::assertInstanceOf(HmPanelApiException::class, $failure);
        self::assertSame(500, $failure->httpStatus);
        self::assertTrue($failure->mayHaveBeenDone(), 'the client may be on the panel');

        foreach (['PATCH' => fn() => $this->client()->patch('/v1/clients/c1', ['enable' => false]), 'DELETE' => fn() => $this->client()->delete('/v1/clients/c1')] as $method => $write) {
            $this->panel->raw(self::json(503, ['statusCode' => 503, 'message' => 'Service Unavailable']));
            self::assertTrue(self::failure($write)->mayHaveBeenDone(), $method);
        }

        $this->panel->raw(self::json(500, ['statusCode' => 500, 'message' => 'Internal server error']));
        self::assertFalse(self::failure(fn() => $this->client()->get('/v1/clients/c1'))->mayHaveBeenDone(), 'a read changes nothing');
    }

    public function testAKeyPastItsCallsPerMinuteLeavesThePanelAloneAWhileAndSaysWhatToDo(): void
    {
        $this->panel->raw(self::json(429, ['statusCode' => 429, 'message' => 'Rate limit exceeded']));

        $failure = self::failure(fn() => $this->client()->get('/v1/clients/search'));

        self::assertInstanceOf(HmPanelApiException::class, $failure);
        self::assertSame([429, 'Rate limit exceeded'], [$failure->httpStatus, $failure->panelMessage]);
        self::assertTrue($failure->unavailable(), 'every call is refused until the minute is over');
        self::assertFalse($failure->mayHaveBeenDone(), 'refused before anything was done');
        self::assertStringContainsString('60', $failure->hint);
        self::assertStringContainsString('«تنظیمات ← API»', $failure->hint);

        $said = ProviderErrorPresenter::explain($failure);
        self::assertStringStartsWith('پنل درخواست را رد کرد. HMPanel هر کلید API', $said['message'], 'the owner reads the remedy');
        self::assertSame('Rate limit exceeded', $said['detail']);
        self::assertSame('سرور در دسترس نبود.', ProviderErrorPresenter::summary($failure));
    }

    public function testAnswersThatAreNotTheApiAreUnexpected(): void
    {
        $this->panel->raw(new Response(301, ['Location' => 'https://hm.test/']));
        $failure = self::failure(fn() => $this->client()->get('/v1/me'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertTrue($failure->isRedirect());
        self::assertSame('https://hm.test/', $failure->location);

        // The panel's own page where the API was expected.
        $this->panel->raw(new Response(200, ['Content-Type' => 'text/html'], '<html><body><div id="root">HMPanel</div></body></html>'));
        $failure = self::failure(fn() => $this->client()->get('/v1/me'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame('HMPanel', $failure->excerpt);
        self::assertNotSame('', $failure->hint);

        // A web server's own 404 page, not Nest's.
        $this->panel->raw(new Response(404, ['Content-Type' => 'text/html'], '<html><body><h1>404 Not Found</h1>nginx</body></html>'));
        $failure = self::failure(fn() => $this->client()->get('/v1/me'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertStringContainsString('2.5', $failure->hint);

        // A reverse proxy whose upstream is down.
        $this->panel->raw(new Response(502, [], ''));
        $failure = self::failure(fn() => $this->client()->get('/v1/me'));
        self::assertInstanceOf(UnexpectedResponseException::class, $failure);
        self::assertSame(502, $failure->httpStatus);
    }

    public function testNothingAnsweringIsAConnectionFailure(): void
    {
        $this->panel->refuse(new ConnectException('cURL error 7: Failed to connect to hm.test port 443: Connection refused', new Request('GET', 'x')));

        $failure = self::failure(fn() => $this->client()->get('/v1/me'));

        self::assertInstanceOf(ConnectionException::class, $failure);
        self::assertSame(ConnectionFailure::Refused, $failure->failure);
    }

    public function testTheServersTimeoutAndTlsChoiceTuneEveryRequestAndRedirectsAreNotFollowed(): void
    {
        $this->panel->raw(new Response(200));
        $client = new HmPanelClient(new PanelHttp($this->panel->client(), new NullLogger()), new PanelConnection(self::BASE, verifyTls: false, timeout: 7), new PanelCredentials(AuthMode::Token, token: self::KEY));

        $client->delete('/v1/clients/c1');

        $options = $this->panel->options(0);
        self::assertFalse($options['verify']);
        self::assertSame(7.0, $options['timeout']);
        self::assertFalse($options['allow_redirects']);
        self::assertFalse($options['http_errors']);
    }

    private function client(): HmPanelClient
    {
        return new HmPanelClient(new PanelHttp($this->panel->client(), new NullLogger()), new PanelConnection(self::BASE), new PanelCredentials(AuthMode::Token, token: self::KEY));
    }

    private static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
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
