<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Http\Middleware\JsonBodyMiddleware;
use App\Core\Http\RequestId;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\HttpTestCase;

/**
 * Every body the shop reads is JSON, read no further than JsonBodyMiddleware::MAX_BYTES before any of it is decoded: a
 * larger one — said by its Content-Length or found as it is read — is the error shape's 413, signed in or not, on any
 * route, Telegram's webhooks too. A file upload keeps its own limit, and no other kind of body is read at all.
 */
final class RequestBodyLimitTest extends HttpTestCase
{
    private const WEBHOOK_SECRET = 'wh-secret-1';

    public function testABodyPastTheLimitIsRefusedBeforeAnyOfItIsRead(): void
    {
        $response = $this->postJson('/api/admin/auth/login', ['username' => str_repeat('a', JsonBodyMiddleware::MAX_BYTES), 'password' => 'secret123']);

        self::assertSame(413, $response->getStatusCode());
        $answer = $this->decode($response);
        self::assertSame(JsonBodyMiddleware::TOO_LARGE, $answer['message']);
        self::assertSame(RequestId::current(), $answer['request_id'], 'the error shape, with the request\'s id');
        self::assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame(200, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD])->getStatusCode(), 'no failed sign-in was counted');
    }

    public function testTheContentLengthAloneRefusesIt(): void
    {
        $response = $this->send('POST', '/api/admin/auth/login', ['username' => 'root', 'password' => 'x'], [
            'X-Requested-With' => 'XMLHttpRequest',
            'Content-Length' => (string) (JsonBodyMiddleware::MAX_BYTES + 1),
        ]);

        self::assertSame(413, $response->getStatusCode());
    }

    public function testTelegramsWebhookIsHeldToItToo(): void
    {
        $this->config(['telegram.webhook_secret' => self::WEBHOOK_SECRET]);
        $update = ['update_id' => 1, 'message' => ['message_id' => 1, 'text' => str_repeat('سلام ', JsonBodyMiddleware::MAX_BYTES / 8), 'chat' => ['id' => self::TELEGRAM_ID, 'type' => 'private'], 'from' => ['id' => self::TELEGRAM_ID, 'first_name' => 'Ali']]];

        $response = $this->send('POST', '/webhooks/telegram/' . self::WEBHOOK_SECRET, $update, ['X-Telegram-Bot-Api-Secret-Token' => self::WEBHOOK_SECRET]);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame([], $this->telegram()->calls(), 'nothing of it was served');
    }

    public function testABodyWithoutALengthIsReadNoFurtherThanTheLimit(): void
    {
        $body = (new StreamFactory())->createStream('{"username": "' . str_repeat('a', JsonBodyMiddleware::MAX_BYTES) . '", "password": "x"}');
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/admin/auth/login')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Transfer-Encoding', 'chunked')
            ->withBody($body);

        self::assertSame(413, $this->app()->http()->handle($request)->getStatusCode());
        self::assertLessThanOrEqual(JsonBodyMiddleware::MAX_BYTES + 65_536, $body->tell(), 'read up to the limit, not to its end');
    }

    public function testAnUploadKeepsItsOwnLimit(): void
    {
        $this->loginAsAdmin();

        $response = $this->upload('/api/admin/bot/qr-background', 'file', 'big.png', str_repeat("\0", JsonBodyMiddleware::MAX_BYTES * 2));

        self::assertSame(422, $response->getStatusCode(), 'past the JSON limit, judged by the upload\'s own rules');
        self::assertArrayHasKey('file', $this->decode($response)['errors']);
    }

    public function testNoOtherKindOfBodyIsRead(): void
    {
        foreach (['application/x-www-form-urlencoded' => 'username=root&password=secret123', 'application/xml' => '<login><username>root</username><password>secret123</password></login>'] as $type => $body) {
            $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/admin/auth/login')
                ->withHeader('X-Requested-With', 'XMLHttpRequest')
                ->withHeader('Content-Type', $type)
                ->withBody((new StreamFactory())->createStream($body));

            $response = $this->app()->http()->handle($request);

            self::assertSame(422, $response->getStatusCode(), "{$type}: the API speaks JSON");
            self::assertArrayHasKey('username', $this->decode($response)['errors']);
        }
    }
}
