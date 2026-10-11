<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http;

use App\Core\Http\Json;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Users\Services\Customers;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Guards a website customer's own routes: the bearer token must open a live session of the request's shop
 * (StoreMiddleware ran first) — otherwise a 401 with `WWW-Authenticate: Bearer` — and its customer must not be banned
 * (403). The request then carries the Customer; the session's use and the customer's visit are recorded as they fall due.
 */
final class CustomerAuthMiddleware implements MiddlewareInterface
{
    public const SIGNED_OUT = 'برای دسترسی باید وارد شوید.';

    public function __construct(
        private readonly CustomerSessions $sessions,
        private readonly Customers $customers,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $bearer) === 1 ? $bearer[1] : null;
        $session = $token === null ? null : $this->sessions->find($token);
        if ($session === null) {
            return Json::error($this->responses->createResponse(), self::SIGNED_OUT, 401)->withHeader('WWW-Authenticate', 'Bearer');
        }

        $user = $session->user;
        if ($user->isBanned()) {
            return Json::error($this->responses->createResponse(), SignInRefusedException::BANNED, 403);
        }

        $this->sessions->touch($session);
        $this->customers->seen($user);

        return $handler->handle($request->withAttribute(Customer::ATTRIBUTE, new Customer($session, $user)));
    }
}
