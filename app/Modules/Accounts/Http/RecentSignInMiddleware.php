<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http;

use App\Core\Http\Json;
use App\Modules\Accounts\Services\CustomerSessions;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Guards what changes how an account is signed in to (ways in, the password, two-factor sign-in, a merge): the session
 * must have proven a way in within CustomerSessions::RECENT_SECONDS, so a leaked bearer token alone cannot take the
 * account over. Otherwise a 403 whose `WWW-Authenticate` asks for a more recent sign-in (RFC 9470) — never a 401, which
 * would tell the site the session ended.
 */
final class RecentSignInMiddleware implements MiddlewareInterface
{
    public const SIGN_IN_AGAIN = 'برای این کار دوباره وارد شوید.';

    /** What the refusal's `WWW-Authenticate` says: a more recent sign-in, at most this many seconds old. */
    public const CHALLENGE = 'Bearer error="insufficient_user_authentication", max_age=' . CustomerSessions::RECENT_SECONDS;

    public function __construct(private readonly ResponseFactoryInterface $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!CustomerSessions::isRecent(Customer::of($request)->session)) {
            return Json::error($this->responses->createResponse(), self::SIGN_IN_AGAIN, 403)->withHeader('WWW-Authenticate', self::CHALLENGE);
        }

        return $handler->handle($request);
    }
}
