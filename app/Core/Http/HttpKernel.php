<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Application;
use App\Core\Http\Middleware\CorsMiddleware;
use App\Core\Http\Middleware\JsonBodyMiddleware;
use App\Core\Http\Middleware\OutputGuardMiddleware;
use App\Core\Http\Middleware\RequestIdMiddleware;
use App\Core\Http\Middleware\SecurityHeadersMiddleware;
use DI\Container;
use Psr\Log\LoggerInterface;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;

/**
 * Builds the Slim application: the prefix it is mounted at, the routes, and what every request goes through. What a
 * request needs besides — the installation, a session, the CSRF header, a signed-in panel — is its route group's
 * middleware (routes/*.php): the router decides what a request is, so no spelling of an address escapes its guards.
 */
final class HttpKernel
{
    /** Route files under routes/ that register HTTP endpoints, in load order. */
    private const ROUTE_FILES = ['web', 'api', 'webhooks'];

    /** @return SlimApp<Container> */
    public static function create(Application $app): SlimApp
    {
        $container = $app->container();

        $slim = AppFactory::create(container: $container);
        $slim->setBasePath($container->get('http.base_path'));

        foreach (self::ROUTE_FILES as $file) {
            $register = require $app->basePath("routes/{$file}.php");
            $register($slim);
        }
        // The router's table read from a file rather than compiled again: made from the route files and Urls' paths.
        $container->get(RouteCache::class)->apply($slim->getRouteCollector(), [
            ...array_map(static fn(string $file): string => $app->basePath("routes/{$file}.php"), self::ROUTE_FILES),
            __DIR__ . '/Urls.php',
        ]);

        // Slim runs middleware in LIFO order: the last one added is the outermost. The body is read once routed, inside
        // the error handling: one too large is the error shape's 413.
        $slim->add(JsonBodyMiddleware::class);
        $slim->addRoutingMiddleware();

        $logger = $container->get(LoggerInterface::class);
        $slim->addErrorMiddleware(displayErrorDetails: $app->isDebug(), logErrors: true, logErrorDetails: true, logger: $logger)
            ->setDefaultErrorHandler(new ErrorHandler($slim->getCallableResolver(), $slim->getResponseFactory(), $logger, $container->get(RequestOrigin::class), $app->basePath()));

        // What PHP prints while a request runs — the error handler's work included — stays out of the answer.
        $slim->add(OutputGuardMiddleware::class);
        // Outside the routing, so a preflight is answered before it (no route takes OPTIONS), and around the error
        // handler, so a page on an allowed origin may read an error answer too.
        $slim->add(CorsMiddleware::class);
        // Around the error handler, so its answers carry them too.
        $slim->add(SecurityHeadersMiddleware::class);
        // Outermost: the request has its id before anything else runs, and every answer says it.
        $slim->add(RequestIdMiddleware::class);

        return $slim;
    }
}
