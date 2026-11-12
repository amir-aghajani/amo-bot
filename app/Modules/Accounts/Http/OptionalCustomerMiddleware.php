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
 * that opens no session is a 401 — never quietly a guest's request — and a banned customer a 403. A panel's preview of
 * the mini app reads there as its customer, and writes nothing (a 403); under a website's key its token is as none.
 */
final class OptionalCustomerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly CustomerAuthMiddleware $customers) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $request->hasHeader('Authorization') ? $this->customers->processOptional($request, $handler) : $handler->handle($request);
    }
}
