<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A website route a guest may use as well as a signed-in customer (POST /reviews, the catalogue's prices). A request
 * without an `Authorization` header goes on as a guest's; one with it goes through CustomerAuthMiddleware whole, so a token
 * that opens no session is a 401 — never quietly a guest's request — and a banned customer a 403.
 */
final class OptionalCustomerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly CustomerAuthMiddleware $customers) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $request->hasHeader('Authorization') ? $this->customers->process($request, $handler) : $handler->handle($request);
    }
}
