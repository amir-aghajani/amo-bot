<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ErrorHandler;
use App\Core\Http\RequestId;
use App\Core\Http\RequestOrigin;
use App\Core\Logging\RequestIdProcessor;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Exceptions\PlanInUseException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Exceptions\MoveException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The error handler's two jobs: the one JSON error shape for API callers — the shop's refusals in their own words,
 * status and fields, a missing row as a 404, anything else as a 500, with a debug block only when details are on and
 * for a request from this machine, no secret nor any call's argument in it — and one log line per server-side failure.
 */
final class ErrorHandlerTest extends TestCase
{
    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new TestHandler();
    }

    public function testAFailureAnswersTheErrorShapeWithoutDetails(): void
    {
        $response = $this->handle(new \RuntimeException('disk on fire'), details: false);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['message' => ErrorHandler::MESSAGES[500]], $this->decode($response), 'the exception stays inside the server');
    }

    public function testDetailsAddADebugBlockForARequestFromThisMachine(): void
    {
        $data = $this->decode($this->handle(new \RuntimeException('disk on fire'), details: true, request: $this->request(['REMOTE_ADDR' => '127.0.0.1'])));

        self::assertSame(ErrorHandler::MESSAGES[500], $data['message']);
        self::assertSame(\RuntimeException::class, $data['debug']['exception']);
        self::assertSame('disk on fire', $data['debug']['detail']);
        self::assertIsList($data['debug']['trace']);
        self::assertNotEmpty($data['debug']['trace']);
        self::assertSame(['message', 'debug'], array_keys($data));
    }

    public function testAServerFailureIsLoggedOnceWithTheException(): void
    {
        $exception = new \RuntimeException('disk on fire');
        $this->handle($exception, details: false);

        self::assertCount(1, $this->log->getRecords(), 'one line, not Slim\'s rendering on top of ours');
        $record = $this->log->getRecords()[0];
        self::assertSame('GET /api/admin/boom -> 500 disk on fire', $record->message);
        self::assertSame($exception, $record->context['exception'], 'the formatter prints the trace from the context');
    }

    public function testADatabaseOutOfReachIsA503ThatSaysSoAndIsLoggedWithoutItsTrace(): void
    {
        $refused = new QueryException('mysql', 'select * from `users`', [], new \PDOException('SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it'));
        $response = $this->handle($refused, details: false);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['message' => ErrorHandler::DATABASE_UNREACHABLE], $this->decode($response), 'the panels show these words as the reason');
        self::assertCount(1, $this->log->getRecords(), 'still a failure, in the log');
        self::assertStringStartsWith('GET /api/admin/boom -> 503 SQLSTATE[HY000] [2002]', $this->log->getRecords()[0]->message);
        self::assertSame([], $this->log->getRecords()[0]->context, 'no trace: where it failed says nothing');

        foreach (['SQLSTATE[HY000] [1045] Access denied for user \'shop\'@\'localhost\' (using password: YES)', 'SQLSTATE[HY000] [1049] Unknown database \'shop\'', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'] as $words) {
            $answer = $this->handle(new QueryException('mysql', 'select 1', [], new \PDOException($words)), details: false);
            self::assertSame([503, ErrorHandler::DATABASE_UNREACHABLE], [$answer->getStatusCode(), $this->decode($answer)['message']], $words);
        }

        $missing = $this->handle(new QueryException('mysql', 'select * from `users`', [], new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'shop.users' doesn't exist")), details: false);
        self::assertSame(500, $missing->getStatusCode(), 'a database that answers with an error is the server failing');
        self::assertSame(['message' => ErrorHandler::MESSAGES[500]], $this->decode($missing));
    }

    public function testEveryConnectionTheDatabaseRefusesIsA503AndAStatementsValuesNeverPassForOne(): void
    {
        // A socket-authenticated user (MySqlDriver::explain() names it too), and a shared host's caps on connections.
        $refusals = [
            "SQLSTATE[HY000] [1698] Access denied for user 'root'@'localhost'",
            'SQLSTATE[08004] [1040] Too many connections',
            "SQLSTATE[HY000] [1203] User shop already has more than 'max_user_connections' active connections",
        ];
        foreach ($refusals as $words) {
            $answer = $this->handle(new QueryException('mysql', 'select 1', [], new \PDOException($words)), details: false);
            self::assertSame([503, ErrorHandler::DATABASE_UNREACHABLE], [$answer->getStatusCode(), $this->decode($answer)['message']], $words);
        }

        $duplicate = new QueryException('mysql', 'insert into `users` (`name`) values (?)', ['SQLSTATE[HY000] [1045] Access denied for user'], new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'));
        self::assertSame(500, $this->handle($duplicate, details: false)->getStatusCode(), 'the driver\'s words decide, not a value the statement carried');
    }

    public function testAClientErrorIsNotLogged(): void
    {
        $request = $this->request();
        $response = $this->handle(new HttpNotFoundException($request), details: false);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['message' => ErrorHandler::MESSAGES[404]], $this->decode($response));
        self::assertSame([], $this->log->getRecords());
    }

    public function testAValidationExceptionAnswersEveryFieldItRefused(): void
    {
        $refused = new ValidationException(['name' => ['نام را وارد کنید.'], 'price' => ['قیمت باید عدد باشد.']]);
        $response = $this->handle($refused, details: false);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['message' => $refused->getMessage(), 'errors' => ['name' => ['نام را وارد کنید.'], 'price' => ['قیمت باید عدد باشد.']]], $this->decode($response));

        $one = $this->decode($this->handle(ValidationException::on('status', 'فقط سفارش‌های پرداخت‌نشده لغو می‌شوند.'), details: false));
        self::assertSame(['message' => 'فقط سفارش‌های پرداخت‌نشده لغو می‌شوند.', 'errors' => ['status' => ['فقط سفارش‌های پرداخت‌نشده لغو می‌شوند.']]], $one, 'one field: its message is the answer\'s too');
    }

    public function testARefusalAnswersItsOwnStatusAndWordsUnderTheFieldItConcerns(): void
    {
        $inUse = $this->handle(new PlanInUseException('این پلن فروخته شده است؛ غیرفعالش کنید.'), details: false);
        self::assertSame(409, $inUse->getStatusCode());
        self::assertSame(['message' => 'این پلن فروخته شده است؛ غیرفعالش کنید.'], $this->decode($inUse), 'about the request as a whole: no errors');

        $noServer = $this->handle(new NoServerAvailableException('سرور «آلمان» جا ندارد.'), details: false);
        self::assertSame(422, $noServer->getStatusCode());
        self::assertSame(['message' => 'سرور «آلمان» جا ندارد.', 'errors' => ['server_id' => ['سرور «آلمان» جا ندارد.']]], $this->decode($noServer));

        $panel = $this->handle(MoveException::previousServer(new Server(['name' => 'آلمان']), new ConnectionException(ConnectionFailure::Timeout, 'Connection timed out')), details: false);
        self::assertSame(502, $panel->getStatusCode(), 'a panel that failed');
        self::assertSame(['previous' => ['سرور قبلی «آلمان»: سرور در دسترس نبود.']], $this->decode($panel)['errors'], 'under the step: the screen offers to leave that server out');
        $service = $this->handle(MoveException::service('سرویس دیگر فعال نیست.'), details: false);
        self::assertSame([422, ['status' => ['سرویس دیگر فعال نیست.']]], [$service->getStatusCode(), $this->decode($service)['errors']]);

        self::assertSame([], $this->log->getRecords(), 'answers, not failures: nothing logged');
    }

    public function testARefusalToTryAgainYetSaysForHowLong(): void
    {
        $response = $this->handle(new TooManyAttemptsException('کمی صبر کنید.', 120), details: false);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('120', $response->getHeaderLine('Retry-After'), 'the wait, as a client reads it');
        self::assertSame(['message' => 'کمی صبر کنید.'], $this->decode($response));
        self::assertSame([], $this->log->getRecords(), 'an answer, not a failure');
    }

    public function testAWrongMethodSaysWhichTheAddressTakes(): void
    {
        $request = $this->request();
        $response = $this->handle((new HttpMethodNotAllowedException($request))->setAllowedMethods(['GET', 'POST']), details: false);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->getHeaderLine('Allow'), 'as HTTP asks of a 405');
        self::assertSame(['message' => ErrorHandler::MESSAGES[405]], $this->decode($response));
        self::assertSame([], $this->log->getRecords());
    }

    public function testAnErrorAnswerNamesTheRequestItAnswers(): void
    {
        RequestId::begin('0123456789abcdef');

        $data = $this->decode($this->handle(new \RuntimeException('disk on fire'), details: false));

        self::assertSame(['message' => ErrorHandler::MESSAGES[500], 'request_id' => '0123456789abcdef'], $data, 'what the panel shows, to find the line below');
        self::assertSame('0123456789abcdef', $this->log->getRecords()[0]->extra['request_id'] ?? null);
    }

    public function testARowThatIsNotThereIsA404(): void
    {
        $response = $this->handle((new ModelNotFoundException())->setModel(Plan::class, [7]), details: false);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['message' => ErrorHandler::NOT_FOUND], $this->decode($response));
        self::assertSame([], $this->log->getRecords());
    }

    public function testTheDebugBlockKeepsNoSecretAndNoArgument(): void
    {
        $token = '123456789:AAF' . str_repeat('x', 32);
        $data = $this->decode($this->handle(self::failingWith("Telegram request getMe failed: https://api.telegram.org/bot{$token}/getMe", '/cron/cron-secret'), details: true, request: $this->request(['REMOTE_ADDR' => '::1'])));

        self::assertSame('Telegram request getMe failed: https://api.telegram.org/bot***/getMe', $data['debug']['detail']);
        $trace = implode("\n", $data['debug']['trace']);
        self::assertStringNotContainsString('cron', $trace, 'what a function was handed is never shown, whatever php.ini keeps');
        self::assertStringContainsString('tests/Unit/Http/ErrorHandlerTest.php', $trace, 'where it happened, from the app\'s folder');
        self::assertStringNotContainsString($this->app()->basePath(), $trace);
    }

    private function handle(\Throwable $exception, bool $details, ?ServerRequestInterface $request = null): ResponseInterface
    {
        $slim = $this->app()->http();
        $handler = new ErrorHandler($slim->getCallableResolver(), $slim->getResponseFactory(), new Logger('test', [$this->log], [new RequestIdProcessor()]), $this->service(RequestOrigin::class), $this->app()->basePath());

        return $handler($request ?? $this->request(), $exception, $details, true, true);
    }

    /** An exception made by a function that was handed `$path`, its trace naming the argument whatever php.ini says. */
    private static function failingWith(string $message, string $path): \RuntimeException
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $maxLength = ini_set('zend.exception_string_param_max_len', '100');
        try {
            return (static fn(string $path): \RuntimeException => new \RuntimeException($message))($path);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
            ini_set('zend.exception_string_param_max_len', (string) $maxLength);
        }
    }

    /** @param array<string, string> $server The connection's server variables (REMOTE_ADDR) */
    private function request(array $server = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/admin/boom', $server)->withHeader('Accept', 'application/json');
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
