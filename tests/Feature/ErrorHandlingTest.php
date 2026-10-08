<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\ApiController;
use App\Core\Http\ErrorHandler;
use App\Core\Http\RequestOrigin;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;

/**
 * What a caller gets when a request goes wrong: the one JSON shape ({message, errors?, request_id}), whoever asks — PHP
 * renders no page, and an answer (a 404, a 405) never carries the details APP_DEBUG adds to a failure.
 */
final class ErrorHandlingTest extends HttpTestCase
{
    public function testAnActionThatLoadsAnotherShopsRowAnswersA404(): void
    {
        $ours = $this->plan(['name' => 'برنز']);
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): Plan => $this->plan(['name' => 'نقره']));

        self::assertSame([200, ['plan' => 'برنز']], $this->ask($ours->id));
        self::assertSame([404, ['message' => ErrorHandler::NOT_FOUND]], CurrentBot::run($bot, fn(): array => $this->ask($ours->id)), 'the main shop\'s plan is not there in the agent\'s shop');
        self::assertSame([200, ['plan' => 'نقره']], CurrentBot::run($bot, fn(): array => $this->ask($theirs->id)));
        self::assertSame(404, $this->ask(999_999)[0]);
    }

    public function testAnUnknownApiRouteAnswersTheOneErrorShape(): void
    {
        $response = $this->get('/api/admin/does-not-exist');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $data = $this->decode($response);
        self::assertSame(ErrorHandler::MESSAGES[404], $data['message']);
        self::assertArrayNotHasKey('error', $data, 'the same top-level shape as a controller\'s error');
        self::assertTrue($this->app()->isDebug(), 'the suite runs with APP_DEBUG on');
        self::assertArrayNotHasKey('debug', $data, 'an answer, not a failure: never the details');
    }

    public function testAMethodTheAddressDoesNotTakeAnswersTheOneErrorShape(): void
    {
        $response = $this->json('PUT', '/health');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(ErrorHandler::MESSAGES[405], $this->decode($response)['message']);
    }

    public function testEvenAnAddressOutsideTheApiAnswersJson(): void
    {
        // The app renders no pages: the panel is static files, and a browser that reaches PHP anyway gets the error shape.
        $response = $this->send('GET', '/nowhere', null, ['Accept' => 'text/html']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(ErrorHandler::MESSAGES[404], $this->decode($response)['message']);
    }

    /**
     * GET /plans/{id} of an action that loads its row and lets whatever goes wrong through, served the way the panel's
     * API is — Slim's error middleware with the app's handler — in whichever shop the call is made.
     *
     * @return array{int, array<string, mixed>} The status and the JSON
     */
    private function ask(int $id): array
    {
        $slim = AppFactory::create();
        $slim->addRoutingMiddleware();
        $slim->addErrorMiddleware(false, false, false)->setDefaultErrorHandler(
            new ErrorHandler($slim->getCallableResolver(), $slim->getResponseFactory(), $this->service(LoggerInterface::class), $this->service(RequestOrigin::class), $this->app()->basePath()),
        );
        $slim->get('/plans/{id:[0-9]+}', new class extends ApiController {
            /** @param array<string, string> $args */
            public function __invoke(Request $request, Response $response, array $args): Response
            {
                return $this->json($response, ['plan' => $this->load(Plan::class, $args)->name]);
            }
        });

        $response = $slim->handle((new ServerRequestFactory())->createServerRequest('GET', "http://localhost/plans/{$id}"));

        return [$response->getStatusCode(), $this->decode($response)];
    }
}
