<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Http\Middleware\JsonBodyMiddleware;
use App\Modules\Store\Models\Website;
use App\Modules\Support\Models\Ticket;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;

/**
 * A form — what a website posts with a picture: a receipt, a ticket's message — is read as a JSON body is: text that is
 * not UTF-8, which JSON cannot carry and the database does not take, is a 422 on its field, never a 500 of the database's
 * or of an encoder's on the way; and one PHP itself took nothing of for its size (past post_max_size — no field, no file
 * reaches the app) is the 413 a body too large is, never «nothing was sent». (A form posted where the website's API takes
 * JSON alone is refused before it is read: StoreBodiesTest.)
 */
final class FormBodyTest extends HttpTestCase
{
    private Website $website;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->website = $this->website();
        $this->token = $this->customerSession($this->customer());
    }

    public function testAFormsTextThatIsNotUtf8IsRefusedUnderItsField(): void
    {
        $response = $this->form(['subject' => "Sa\xC3\x28ra's service", 'body' => 'از دیشب وصل نمی‌شود.']);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['subject' => [Input::NOT_UTF8]], $this->decode($response)['errors']);
        self::assertSame(0, Ticket::query()->count(), 'nothing kept');

        // The same form, its text whole: the ticket is opened.
        self::assertSame(201, $this->form(['subject' => 'سرویس سارا', 'body' => 'از دیشب وصل نمی‌شود.'])->getStatusCode());
    }

    public function testAFormPhpTookNothingOfForItsSizeIsTooLarge(): void
    {
        $limit = ini_parse_quantity((string) ini_get('post_max_size'));
        if ($limit <= 0) {
            self::markTestSkipped('This PHP takes a POST of any size: it empties none.');
        }

        // What reaches the app of a picture past post_max_size: its Content-Length, and nothing else.
        $emptied = $this->form([], ['Content-Length' => (string) ($limit + 1)]);

        self::assertSame(413, $emptied->getStatusCode(), 'not «fill in the form»: PHP took none of it');
        self::assertSame(JsonBodyMiddleware::TOO_LARGE, $this->decode($emptied)['message']);

        // One whose fields did come is no body PHP emptied, whatever its length says: its route judges it.
        $arrived = $this->form(['subject' => 'سرویس سارا', 'body' => 'از دیشب وصل نمی‌شود.'], ['Content-Length' => (string) ($limit + 1)]);
        self::assertSame(201, $arrived->getStatusCode(), (string) $arrived->getBody());
    }

    /**
     * A ticket opened by a form posted to the website's API — its fields as PHP parses them —, straight to the app: a form
     * that is not UTF-8, or that PHP emptied, is no request the description takes.
     *
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    private function form(array $fields, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost' . $this->storeApi($this->website, '/tickets'))
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=amobot-test');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app()->http()->handle($fields === [] ? $request : $request->withParsedBody($fields));
    }
}
