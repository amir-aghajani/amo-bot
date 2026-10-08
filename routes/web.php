<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;

/*
 * The site itself has nothing to show: the panels are static files under /admin/ and /agent/, served by the web server,
 * and everything PHP answers is JSON. The site's root opens the owner's panel.
 */
return static function (App $app): void {
    // Not static: Slim binds a route's closure to the container.
    $app->get('/', function (Request $request, Response $response): Response {
        return $response->withStatus(302)->withHeader('Location', RouteContext::fromRequest($request)->getBasePath() . '/admin/');
    });
};
