<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Http\RouteCache;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The router's table kept in a file: made once from the routes, then read instead of compiled again — routing exactly
 * as without it; a new one when a source of the routes changed, the old one gone once nobody can be reading it; one that
 * does not read back dropped; and no folder to write in means no table and no error.
 */
final class RouteCacheTest extends TestCase
{
    private string $dir;

    private string $source;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/amobot-route-cache-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->source = $this->dir . '/routes.php';
        file_put_contents($this->source, '<?php // the routes');
    }

    protected function tearDown(): void
    {
        $everything = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($everything as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function testTheTableIsMadeOnceThenReadAndRoutesAsWithoutIt(): void
    {
        $app = $this->app($this->dir . '/cache');
        self::assertCount(1, $this->tables(), 'made from the routes');
        self::assertSame($this->tables()[0], $app->getRouteCollector()->getCacheFile());
        self::assertSame('order 12', $this->get($app, '/shop/orders/12'));

        $table = $this->tables();
        $again = $this->app($this->dir . '/cache');
        self::assertSame($table, $this->tables(), 'the next request reads the same table');
        self::assertSame('order 7', $this->get($again, '/shop/orders/7'));
        self::assertSame('created', $this->get($again, '/shop/orders', 'POST'));
        $this->assertNotRouted($again, '/shop/orders/x', HttpNotFoundException::class);
        $this->assertNotRouted($again, '/shop/orders/7', HttpMethodNotAllowedException::class, 'DELETE');
    }

    public function testAChangedSourceMakesAnotherTableAndTheOldOneGoesOnceNobodyCanBeReadingIt(): void
    {
        $this->app($this->dir . '/cache');
        [$first] = $this->tables();

        touch($this->source, time() + 5);
        clearstatcache();
        $this->app($this->dir . '/cache');
        self::assertCount(2, $this->tables(), 'another table; the old one stays while a request may be reading it');

        touch($first, time() - RouteCache::KEEP_OLD_SECONDS - 1);
        file_put_contents($this->source, '<?php // the routes, edited');
        clearstatcache();
        $app = $this->app($this->dir . '/cache');
        self::assertNotContains($first, $this->tables(), 'gone once nobody can be reading it');
        self::assertSame('order 3', $this->get($app, '/shop/orders/3'));
    }

    public function testATableThatDoesNotReadBackIsDroppedAndMadeAgain(): void
    {
        $this->app($this->dir . '/cache');
        [$table] = $this->tables();

        foreach (['<?php return "not a table";', '<?php return [', ''] as $broken) {
            file_put_contents($table, $broken);
            $app = $this->app($this->dir . '/cache');
            self::assertNull($app->getRouteCollector()->getCacheFile(), 'not read: routing as without it');
            self::assertFileDoesNotExist($table);
            self::assertSame('order 5', $this->get($app, '/shop/orders/5'));

            $this->app($this->dir . '/cache');
            self::assertFileExists($table, 'made again by the next request');
        }
    }

    public function testWithoutAFolderToWriteInNothingIsKeptAndRoutingWorks(): void
    {
        // A folder that cannot be made: its parent is a file.
        foreach ([$this->source . '/cache', null] as $directory) {
            $app = $this->app($directory);
            self::assertNull($app->getRouteCollector()->getCacheFile());
            self::assertSame('order 9', $this->get($app, '/shop/orders/9'));
        }
    }

    /**
     * A Slim app with the routes of a request, mounted at /shop, its table kept in `$directory`.
     *
     * @return App<ContainerInterface|null>
     */
    private function app(?string $directory): App
    {
        $app = AppFactory::create();
        $app->setBasePath('/shop');
        $app->get('/orders/{id:[0-9]+}', fn(Request $request, Response $response, array $args): Response => self::say($response, "order {$args['id']}"));
        $app->post('/orders', fn(Request $request, Response $response): Response => self::say($response, 'created'));
        $app->addRoutingMiddleware();
        (new RouteCache($directory))->apply($app->getRouteCollector(), [$this->source]);

        return $app;
    }

    private static function say(Response $response, string $words): Response
    {
        $response->getBody()->write($words);

        return $response;
    }

    /** @param App<ContainerInterface|null> $app */
    private function get(App $app, string $path, string $method = 'GET'): string
    {
        return (string) $app->handle((new ServerRequestFactory())->createServerRequest($method, $path))->getBody();
    }

    /**
     * @param App<ContainerInterface|null> $app
     * @param class-string<\Throwable> $refusal
     */
    private function assertNotRouted(App $app, string $path, string $refusal, string $method = 'GET'): void
    {
        try {
            $this->get($app, $path, $method);
        } catch (\Throwable $e) {
            self::assertInstanceOf($refusal, $e);

            return;
        }
        self::fail("{$method} {$path} was routed.");
    }

    /** @return list<string> The tables kept */
    private function tables(): array
    {
        return glob($this->dir . '/cache/routes-*.php') ?: [];
    }
}
