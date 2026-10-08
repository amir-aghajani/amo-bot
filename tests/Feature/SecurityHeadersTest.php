<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Application;
use App\Core\Config\Repository as Config;
use App\Core\Database\DatabaseManager;
use App\Core\Http\Middleware\SecurityHeadersMiddleware;
use App\Core\Http\RequestOrigin;
use App\Core\Installation;
use App\Modules\Api\Controllers\HealthController;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;

/**
 * Every answer PHP gives carries the security headers whatever serves it (nginx and `php -S` read no .htaccess), the
 * error answers included: nothing in it may run on the panels' origin, and the panel's API is never cached — unless an
 * answer said otherwise itself (a streamed picture keeps its caching). Over HTTPS, HSTS. And the public health check
 * names nothing about the server.
 */
final class SecurityHeadersTest extends HttpTestCase
{
    public function testAnAnswerCarriesThem(): void
    {
        $response = $this->get('/health');

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('same-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('camera=(), microphone=(), geolocation=(), payment=()', $response->getHeaderLine('Permissions-Policy'));
        self::assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $response->getHeaderLine('Content-Security-Policy'), 'opened in a tab, it runs nothing');
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testAnErrorAnswerCarriesThemToo(): void
    {
        $response = $this->get('/api/admin/auth/me');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $missing = $this->get('/no/such/address');
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('DENY', $missing->getHeaderLine('X-Frame-Options'));
        self::assertStringContainsString('sandbox', $missing->getHeaderLine('Content-Security-Policy'));
    }

    public function testAnAnswerThatSetsAHeaderOfItsOwnKeepsItsWord(): void
    {
        $picture = $this->through(static fn(ResponseInterface $response): ResponseInterface => $response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Cache-Control', 'private, max-age=300')
            ->withHeader('Referrer-Policy', 'no-referrer'), 'https://shop.example/api/admin/bot/qr-background');

        self::assertSame('private, max-age=300', $picture->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $picture->getHeaderLine('Referrer-Policy'), 'each header, not only the caching');
        self::assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $picture->getHeaderLine('Content-Security-Policy'), 'the ones it did not set are added');
        self::assertSame('nosniff', $picture->getHeaderLine('X-Content-Type-Options'));

        $pinned = $this->through(static fn(ResponseInterface $response): ResponseInterface => $response->withHeader('Strict-Transport-Security', 'max-age=60'), 'https://shop.example/health');
        self::assertSame(['max-age=60'], $pinned->getHeader('Strict-Transport-Security'), 'nor is HSTS written twice');
    }

    public function testAStreamedPictureKeepsItsCachingAndStillRunsNothing(): void
    {
        $this->loginAsAdmin();

        $picture = $this->get('/api/admin/bot/qr-background');

        self::assertSame(200, $picture->getStatusCode());
        self::assertSame('image/jpeg', $picture->getHeaderLine('Content-Type'));
        self::assertSame('private, no-cache', $picture->getHeaderLine('Cache-Control'), 'the controller\'s word, not no-store');
        self::assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $picture->getHeaderLine('Content-Security-Policy'));
    }

    public function testHttpsKeepsTheBrowserOnHttps(): void
    {
        self::assertSame('', $this->get('/health')->getHeaderLine('Strict-Transport-Security'), 'not over plain HTTP');
        self::assertSame('max-age=31536000', $this->get('/health', ['X-Forwarded-Proto' => 'https'])->getHeaderLine('Strict-Transport-Security'), 'behind a proxy that speaks HTTPS');
        self::assertSame('max-age=31536000', $this->get('/health', ['CF-Visitor' => '{"scheme":"https"}'])->getHeaderLine('Strict-Transport-Security'), 'behind Cloudflare');
        self::assertSame('max-age=31536000', $this->through(static fn(ResponseInterface $response): ResponseInterface => $response, 'https://shop.example/health')->getHeaderLine('Strict-Transport-Security'), 'over HTTPS itself');
    }

    public function testTheHealthCheckNamesNothingAboutTheServer(): void
    {
        self::assertSame(['status' => 'ok', 'version' => Application::VERSION, 'installed' => true, 'database' => 'ok'], $this->decode($this->get('/health')), 'no PHP version, no host');

        // A database that does not answer (a folder where its file should be): its error names the host, the user, the
        // file — the log's, never the caller's. Only the health check is handed it: the shop's own manager stays as booted.
        mkdir($file = $this->scratchDir() . '/shop.sqlite');
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => $file, 'prefix' => '']);
        $down = new DatabaseManager($this->service(Config::class), $capsule, $this->app()->container()->get('database.drivers'), $this->service(LoggerInterface::class));
        $this->swap(HealthController::class, new HealthController($this->service(Installation::class), $down));
        $logs = $this->logs();

        $down = $this->get('/health');

        self::assertSame(503, $down->getStatusCode(), 'an uptime monitor sees it');
        self::assertSame(['status' => 'degraded', 'version' => Application::VERSION, 'installed' => true, 'database' => 'unavailable'], $this->decode($down));
        self::assertTrue($logs->hasWarningThatContains('The database does not answer: SQLSTATE[HY000] [14] unable to open database file'), 'why, for the operator');
        self::assertTrue($logs->hasWarningThatContains('shop.sqlite'));
    }

    /** @param \Closure(ResponseInterface): ResponseInterface $answer What the app answered, before the middleware */
    private function through(\Closure $answer, string $url = 'http://localhost/api/admin/bot/qr-background'): ResponseInterface
    {
        $handler = new class ($answer) implements RequestHandlerInterface {
            /** @param \Closure(ResponseInterface): ResponseInterface $answer */
            public function __construct(private readonly \Closure $answer) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->answer)((new ResponseFactory())->createResponse());
            }
        };

        return (new SecurityHeadersMiddleware(new RequestOrigin()))->process((new ServerRequestFactory())->createServerRequest('GET', $url), $handler);
    }
}
