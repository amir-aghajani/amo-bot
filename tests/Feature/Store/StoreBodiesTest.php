<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Store\Http\JsonBodiesMiddleware;
use App\Modules\Store\Models\Website;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Fakes\RecordingMailTransport;
use Tests\HttpTestCase;

/**
 * The Store API takes JSON: a form, text, or a body that says nothing of itself is a 415 — what a page of another site
 * could make its visitors' browsers send without a preflight never reaches a sign-up, a sign-in or a reset. A route
 * that takes a picture (a receipt, a ticket's message) takes a form too; a request that carries nothing is its route's.
 */
final class StoreBodiesTest extends HttpTestCase
{
    /** A PNG of one pixel: a picture by its bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Website $website;

    private RecordingMailTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->mail = $this->mail();
        $this->website = $this->website(['email_signup' => true]);
    }

    public function testWhatAnotherSitesPageSendsWithoutAPreflightIsRefused(): void
    {
        $forgot = $this->storeApi($this->website, '/auth/password/forgot');

        foreach ([
            'a form' => ['application/x-www-form-urlencoded', 'email=sara%40example.com', ['email' => 'sara@example.com']],
            'a multipart form' => ['multipart/form-data; boundary=x', '', ['email' => 'sara@example.com']],
            'text that reads as JSON' => ['text/plain', '{"email":"sara@example.com"}', null],
            'a body that says nothing of itself' => ['', '{"email":"sara@example.com"}', null],
        ] as $what => [$type, $body, $parsed]) {
            $response = $this->raw('POST', $forgot, $type, $body, $parsed);

            self::assertSame([415, JsonBodiesMiddleware::NOT_JSON], [$response->getStatusCode(), $this->decode($response)['message']], $what);
        }
        self::assertSame([], $this->mail->sent(), 'nobody was emailed');

        self::assertSame(202, $this->postJson($forgot, ['email' => 'sara@example.com'])->getStatusCode(), 'JSON is taken');
    }

    public function testEveryChangeTakesJsonAlone(): void
    {
        foreach (['PATCH' => '/me', 'PUT' => '/me/password', 'POST' => '/notifications/read'] as $method => $path) {
            $response = $this->raw($method, $this->storeApi($this->website, $path), 'application/x-www-form-urlencoded', 'first_name=Sarah', ['first_name' => 'Sarah']);

            self::assertSame(415, $response->getStatusCode(), "{$method} {$path}");
        }
    }

    public function testARouteThatTakesAPictureTakesAFormToo(): void
    {
        $this->bearer($this->customerSession($this->webCustomer()));

        $opened = $this->upload($this->storeApi($this->website, '/tickets'), 'file', 'shot.png', (string) base64_decode(self::PNG), ['subject' => 'Slow', 'body' => 'It is slow.']);
        self::assertSame(201, $opened->getStatusCode(), (string) $opened->getBody());

        $text = $this->raw('POST', $this->storeApi($this->website, '/tickets'), 'text/plain', 'subject=Slow', null);
        self::assertSame([415, JsonBodiesMiddleware::NOT_JSON_OR_FORM], [$text->getStatusCode(), $this->decode($text)['message']], 'a form or JSON: nothing else');
    }

    public function testARequestThatCarriesNothingIsItsRoutes(): void
    {
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/auth/nonce'))->getStatusCode());
    }

    /**
     * A request as a browser sends it from any page — `$type` (none when blank), the bytes, and what PHP parses of a form —,
     * straight into the app: the description takes no such body, and the point is what the app makes of one. It is
     * refused before anyone's sign-in is asked, so it carries none.
     *
     * @param array<string, string>|null $parsed
     */
    private function raw(string $method, string $path, string $type, string $body, ?array $parsed): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
            ->withHeader('Content-Length', (string) strlen($body))
            ->withBody((new StreamFactory())->createStream($body))
            ->withParsedBody($parsed);

        return $this->app()->http()->handle($type === '' ? $request : $request->withHeader('Content-Type', $type));
    }
}
