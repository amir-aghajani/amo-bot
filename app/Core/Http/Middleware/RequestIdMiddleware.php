<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\RequestId;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The outermost middleware: gives the request its id before anything runs and sends it in `X-Request-Id`. The id is
 * always made here, never taken from the request.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = RequestId::generate();
        RequestId::begin($id);

        return $handler->handle($request)->withHeader(RequestId::HEADER, $id);
    }
}
