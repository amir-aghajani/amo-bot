<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\CorsPolicy;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS for the addresses a CorsPolicy opens to other origins (a shop's website calling the Store API). For an allowed
 * origin a preflight is answered here, before routing, and every other answer — errors too, since this wraps the error
 * handler — may be read, with the extra headers a client needs. Any other origin gets nothing added. Never credentials:
 * the API uses bearer tokens, not cookies.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    private const METHODS = 'GET, POST, PUT, PATCH, DELETE';

    /** What a page may send besides the simple headers: its customer's token, JSON, an order's idempotency key. */
    private const HEADERS = 'Authorization, Content-Type, Idempotency-Key';

    /** What a page may read of an answer besides the simple headers. */
    private const EXPOSED = 'X-Request-Id, Retry-After, WWW-Authenticate';

    /** Seconds a browser keeps a preflight's answer before asking again. */
    private const MAX_AGE = 600;

    public function __construct(
        private readonly CorsPolicy $policy,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '' || !$this->policy->allows($request, $origin)) {
            return $handler->handle($request);
        }

        if ($request->getMethod() === 'OPTIONS') {
            return $this->responses->createResponse(204)
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Methods', self::METHODS)
                ->withHeader('Access-Control-Allow-Headers', self::HEADERS)
                ->withHeader('Access-Control-Max-Age', (string) self::MAX_AGE)
                ->withAddedHeader('Vary', 'Origin');
        }

        return $handler->handle($request)
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Expose-Headers', self::EXPOSED)
            ->withAddedHeader('Vary', 'Origin');
    }
}
