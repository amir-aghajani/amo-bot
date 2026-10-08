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
 * Guards what a customer of the shop's website reads and does for themselves: the request's bearer token
 * (`Authorization: Bearer …`) must open a session of the shop the request is worked in (StoreMiddleware ran first) —
 * none, one that ended, or another shop's is a 401 —, and its customer must not be banned (a 403). The request then
 * carries the Customer; the session's use and the customer's presence are written as they are due (a visit alone
 * quietly, like a message to the bot).
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
