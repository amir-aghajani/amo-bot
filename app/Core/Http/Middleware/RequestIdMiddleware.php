<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\RequestId;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The outermost middleware: every request gets an id of its own (RequestId) before anything else runs — so every log
 * line it leaves and every error answer carries it — and every answer says it in `X-Request-Id`, the error answers too.
 * The id is always made here, never taken from the request: anyone can write a header.
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
