<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A route of the shop's website a guest takes as well as a signed-in customer (a review written, POST /reviews): anyone
 * without a bearer token, or, their token with the request, the customer it signs in, through the customer's own door
 * whole (CustomerAuthMiddleware) — a token that opens no session is its 401, never a guest's request in its place, and a
 * banned customer its 403.
 */
final class OptionalCustomerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly CustomerAuthMiddleware $customers) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $request->hasHeader('Authorization') ? $this->customers->process($request, $handler) : $handler->handle($request);
    }
}
