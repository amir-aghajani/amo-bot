<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Http\Json;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * The one JSON encoder of every answer: Persian and paths as they are, a byte that is not UTF-8 (a name a panel sent)
 * replaced rather than failing the answer, anything else it cannot encode thrown — never an empty body under a 200 —,
 * and the one error shape.
 */
final class JsonTest extends TestCase
{
    public function testAnAnswerIsJsonWithPersianAndSlashesAsTheyAre(): void
    {
        $response = Json::respond(self::response(), ['name' => 'آلمان', 'link' => 'https://fake.test/sub/ali_1'], 201);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"name":"آلمان","link":"https://fake.test/sub/ali_1"}', (string) $response->getBody());
    }

    public function testAByteThatIsNotUtf8IsReplacedNotFatal(): void
    {
        $response = Json::respond(self::response(), ['client' => "ali\xB1_1"]);

        self::assertSame(['client' => "ali\u{FFFD}_1"], json_decode((string) $response->getBody(), true));
    }

    public function testAnythingElseItCannotEncodeIsTheCodesMistakeAndThrows(): void
    {
        $this->expectException(\JsonException::class);

        Json::respond(self::response(), ['ratio' => NAN]);
    }

    public function testTheErrorShapeCarriesFieldsAndDetailsOnlyWhenThereAreAny(): void
    {
        $plain = Json::error(self::response(), 'پیدا نشد.', 404);
        self::assertSame([404, ['message' => 'پیدا نشد.']], [$plain->getStatusCode(), json_decode((string) $plain->getBody(), true)]);

        $full = Json::error(self::response(), 'نامعتبر است.', 422, ['name' => ['نام را وارد کنید.']], ['exception' => 'X', 'detail' => 'y', 'trace' => []]);
        self::assertSame(['message', 'errors', 'debug'], array_keys((array) json_decode((string) $full->getBody(), true)));
    }

    private static function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }
}
