<?php

declare(strict_types=1);

namespace App\Modules\Store\Http;

use App\Core\Exceptions\DomainRuleException;
use App\Core\Http\Json;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Accounts\Http\RecentSignInMiddleware;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Auth\CurrentPrincipal;
use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Store\Enums\StaffGrant;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;

/**
 * The door of the website's admin API (`/api/store/v1/{store}/admin/*`): the panels' daily-work operations, used by one
 * of the shop's admins signed in on its website (CustomerAuthMiddleware ran first). Shut by default; each check is a 403,
 * asked in this order:
 * 1. the customer is an admin of the shop (anyone else learns nothing more);
 * 2. the website lets its admins in (`staff_enabled`);
 * 3. while it asks a strong sign-in, the session is strong (Telegram, Google, or a password with its second step);
 * 4. a way in was proven on the session within SIGN_IN_HOURS — a working day, so a leaked admin token works for hours,
 *    not for the months a customer's session lasts;
 * 5. an operation that needs a grant (StaffGrant::ARGUMENT on its route) is granted, and then asks a recent sign-in too,
 *    as does one marked RECENT_SIGN_IN (approving a payment, which delivers a service by the admin's word alone).
 * The request then carries the staff Principal, and every change it asks is logged with them on the line.
 */
final class StaffMiddleware implements MiddlewareInterface
{
    /** The refusal anyone but the shop's admins gets, worded for a customer (they may be one). */
    public const NOT_STAFF = 'این بخش فقط برای پشتیبانی فروشگاه است.';
    public const STAFF_OFF = 'این وب‌سایت فعلا مدیران فروشگاه را راه نمی‌دهد.';
    public const SIGN_IN_STRONGLY = 'برای کار مدیریت، با تلگرام، گوگل یا رمز عبور همراه کد ورود دو مرحله‌ای وارد شوید.';
    public const NOT_GRANTED = 'فروشگاه این کار را به مدیران وب‌سایت نسپرده است.';

    /** What a session signed in with a password alone is asked (RFC 9470): a strong way, which POST /me/reauthenticate proves. */
    public const STRONG_CHALLENGE = 'Bearer error="insufficient_user_authentication", acr_values="telegram google password_2fa"';

    /** How long a way in proven on an admin's session lets it work the admin API: a working day. */
    public const SIGN_IN_HOURS = 12;

    /** The route argument of an operation that asks a recent sign-in without needing a grant: approving a payment. */
    public const RECENT_SIGN_IN = 'staff_recent_sign_in';

    /** The methods that change nothing, left out of the log. */
    private const READS = ['GET', 'HEAD'];

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $customer = Customer::of($request);
        $website = StoreMiddleware::website($request);
        if (!$customer->user->isAdmin()) {
            return $this->refuse(self::NOT_STAFF);
        }
        if (!$website->staff_enabled) {
            return $this->refuse(self::STAFF_OFF);
        }
        if ($website->staff_strong_sign_in && !CustomerSessions::isStrong($customer->session)) {
            return $this->refuse(self::SIGN_IN_STRONGLY)->withHeader('WWW-Authenticate', self::STRONG_CHALLENGE);
        }
        if (self::signedInUntil($customer->session) === null) {
            return $this->signInAgain();
        }
        $route = RouteContext::fromRequest($request)->getRoute();
        $grant = self::grantOf($route);
        if ($grant !== null && !in_array($grant->value, $website->staffGrants(), true)) {
            return $this->refuse(self::NOT_GRANTED);
        }
        if (($grant !== null || $route?->getArgument(self::RECENT_SIGN_IN) !== null) && !CustomerSessions::isRecent($customer->session)) {
            return $this->signInAgain();
        }

        $principal = new Principal(PrincipalKind::Staff, Reviewers::forAdmin($customer->user), $website->shop(), $customer->user);

        return CurrentPrincipal::run($principal, function () use ($request, $handler, $principal): ResponseInterface {
            try {
                $response = $handler->handle($request->withAttribute(Principal::ATTRIBUTE, $principal));
            } catch (DomainRuleException $refused) {
                $this->changed($request, $refused->status());

                throw $refused;
            }
            $this->changed($request, $response->getStatusCode());

            return $response;
        });
    }

    /** Until when the session may use the admin API: SIGN_IN_HOURS after a way in was last proven on it; null once past. */
    public static function signedInUntil(CustomerSession $session): ?Carbon
    {
        return CustomerSessions::provenUntil($session, self::SIGN_IN_HOURS * 3600);
    }

    /**
     * Log a change the admin asked for and its status (the log line names them); reads are not logged. A server failure
     * is the error handler's own line.
     */
    private function changed(ServerRequestInterface $request, int $status): void
    {
        if (!in_array($request->getMethod(), self::READS, true)) {
            $this->logger->info('A shop admin on the website asked {method} {path}: {status}', ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath(), 'status' => $status]);
        }
    }

    /** The grant the route's operation asks (StaffGrant::ARGUMENT); null for the shop's daily work. */
    private static function grantOf(?RouteInterface $route): ?StaffGrant
    {
        $named = $route?->getArgument(StaffGrant::ARGUMENT);

        return $named === null ? null : StaffGrant::tryFrom($named) ?? throw new \LogicException("The route names no grant there is: {$named}.");
    }

    private function refuse(string $message): ResponseInterface
    {
        return Json::error($this->responses->createResponse(), $message, 403);
    }

    /** The customers' own "sign in again" refusal, asking for POST /me/reauthenticate. */
    private function signInAgain(): ResponseInterface
    {
        return $this->refuse(RecentSignInMiddleware::SIGN_IN_AGAIN)->withHeader('WWW-Authenticate', RecentSignInMiddleware::CHALLENGE);
    }
}
