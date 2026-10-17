<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Database\Sorting;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Support\Validation;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * What every action shares besides the JSON: an on/off read strictly, a reorder's ids held to what one takes, and
 * bytes answered with their length and caching — shown when they are a picture the panels show, handed over to be saved
 * otherwise. (load() is ErrorHandlingTest's: a missing row is the handler's 404; the policy that lets nothing run is on
 * every answer, SecurityHeadersTest.)
 */
final class ApiControllerTest extends TestCase
{
    public function testASwitchIsOnOrOffAndNothingElse(): void
    {
        $action = new class extends ApiController {
            public function read(Request $request): bool
            {
                return $this->switch($request, 'is_active');
            }
        };

        foreach ([[true, true], [false, false], ['1', true], ['0', false], [1, true], ['false', false], ['TRUE', true]] as [$sent, $read]) {
            self::assertSame($read, $action->read($this->patch(['is_active' => $sent])), var_export($sent, true));
        }

        foreach ([[], ['is_active' => 'yes'], ['is_active' => 'garbage'], ['is_active' => null], ['is_active' => 2]] as $body) {
            try {
                $action->read($this->patch($body));
                self::fail('read ' . json_encode($body) . ' as a switch');
            } catch (ValidationException $e) {
                self::assertSame(['is_active' => [Validation::NOT_A_SWITCH]], $e->errors(), 'never read as "off"');
                self::assertSame(422, $e->status());
            }
        }
    }

    public function testAReorderTakesAsManyIdsAsOneTakesAndNoMore(): void
    {
        $action = new class extends ApiController {
            /** @return list<int> */
            public function read(Request $request): array
            {
                return $this->reorderIds($request);
            }
        };

        self::assertSame(range(1, Sorting::MAX_IDS), $action->read($this->patch(['ids' => array_map(strval(...), range(1, Sorting::MAX_IDS))])), 'as many as a list may have');

        // One past them is refused before any is read: a garbled id among them is not what it is told.
        foreach (['one more' => range(1, Sorting::MAX_IDS + 1), 'one more, garbled' => [...range(1, Sorting::MAX_IDS), '2abc']] as $why => $ids) {
            try {
                $action->read($this->patch(['ids' => $ids]));
                self::fail("{$why}: taken");
            } catch (ValidationException $e) {
                self::assertSame(['ids'], array_keys($e->errors()), $why);
                self::assertStringContainsString('بلندتر از حد', $e->getMessage(), $why);
                self::assertSame(422, $e->status());
            }
        }
    }

    public function testAPictureIsShownAsItIs(): void
    {
        $response = self::answer('JPEG-BYTES', 'image/jpeg', 'private, max-age=300', 'رسید ۱۲.jpg');

        self::assertSame('JPEG-BYTES', (string) $response->getBody());
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
        self::assertSame('10', $response->getHeaderLine('Content-Length'));
        self::assertSame('private, max-age=300', $response->getHeaderLine('Cache-Control'));
        self::assertSame("inline; filename=\"_______.jpg\"; filename*=UTF-8''%D8%B1%D8%B3%DB%8C%D8%AF%20%DB%B1%DB%B2.jpg", $response->getHeaderLine('Content-Disposition'), 'a plain-ASCII name and the real one');

        self::assertFalse(self::answer('x', 'image/png', 'no-cache')->hasHeader('Content-Disposition'), 'no name, no disposition');
        self::assertSame('video/webm', self::answer('x', 'video/webm', 'no-cache')->getHeaderLine('Content-Type'), 'a sticker\'s video plays in the panel');
    }

    public function testAnythingElseIsHandedOverToBeSavedNeverRendered(): void
    {
        foreach (['image/svg+xml', 'text/html', 'application/pdf', 'image/heic'] as $mime) {
            $response = self::answer('<svg onload="alert(1)"/>', $mime, 'private, max-age=300');

            self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'), $mime);
            self::assertSame('attachment', $response->getHeaderLine('Content-Disposition'), $mime);
        }

        $named = self::answer('%PDF', 'application/pdf', 'no-cache', "receipt\r\nSet-Cookie: x.pdf");
        self::assertSame("attachment; filename=\"receipt__Set-Cookie__x.pdf\"; filename*=UTF-8''receipt%0D%0ASet-Cookie%3A%20x.pdf", $named->getHeaderLine('Content-Disposition'), 'a name the sender chose cannot break the header');
        self::assertSame(120, mb_strlen(urldecode(explode("''", self::answer('x', 'image/png', 'no-cache', str_repeat('ب', 300) . '.png')->getHeaderLine('Content-Disposition'))[1])), 'nor run on');
    }

    private static function answer(string $body, string $mime, string $cache, ?string $filename = null): Response
    {
        $action = new class extends ApiController {
            public function answer(string $body, string $mime, string $cache, ?string $filename): Response
            {
                return $this->bytes((new ResponseFactory())->createResponse(), $body, $mime, $cache, $filename);
            }
        };

        return $action->answer($body, $mime, $cache, $filename);
    }

    /** @param array<string, mixed> $body */
    private function patch(array $body): Request
    {
        return (new ServerRequestFactory())->createServerRequest('PATCH', 'http://localhost/api/admin/plans/1')->withParsedBody($body);
    }
}
