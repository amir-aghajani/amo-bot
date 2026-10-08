<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Http\Json;
use App\Core\Http\Middleware\OutputGuardMiddleware;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * What PHP prints while a request runs — a warning a host prints whatever the app says, a stray echo — never reaches the
 * answer: the JSON stays JSON, no path of the server's goes out, and the log says it happened. (The suite is strict about
 * output: a line that got through would fail the test as well.)
 */
final class OutputGuardMiddlewareTest extends TestCase
{
    public function testWhatARequestPrintsStaysOutOfItsAnswerAndIsLogged(): void
    {
        $logs = new TestHandler();
        $level = ob_get_level();

        $response = (new OutputGuardMiddleware(new Logger('test', [$logs])))->process(self::request(), self::handler(static function (): void {
            echo "Warning: Undefined variable \$plan in /home/shop/app/Modules/Catalog/PlanService.php on line 42\n";
            // A buffer the request opened and never closed.
            ob_start();
            echo 'half of something';
        }));

        self::assertSame('{"plans":[]}', (string) $response->getBody());
        self::assertSame($level, ob_get_level(), 'every buffer the request opened is closed');
        self::assertTrue($logs->hasWarningThatContains('printed output outside its answer'));
        $context = $logs->getRecords()[0]->context;
        self::assertSame(['GET', '/api/admin/plans'], [$context['method'], $context['path']]);
        self::assertStringStartsWith('Warning: Undefined variable $plan', $context['output']);
        self::assertStringEndsWith('half of something', $context['output']);
    }

    public function testARequestThatPrintsNothingLogsNothing(): void
    {
        $logs = new TestHandler();

        $response = (new OutputGuardMiddleware(new Logger('test', [$logs])))->process(self::request(), self::handler(static fn() => null));

        self::assertSame('{"plans":[]}', (string) $response->getBody());
        self::assertSame([], $logs->getRecords());
    }

    private static function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/admin/plans');
    }

    /** A handler that runs `$prints`, then answers the plans as JSON. */
    private static function handler(\Closure $prints): RequestHandlerInterface
    {
        return new class ($prints) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $prints) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->prints)();

                return Json::respond((new ResponseFactory())->createResponse(), ['plans' => []]);
            }
        };
    }
}
