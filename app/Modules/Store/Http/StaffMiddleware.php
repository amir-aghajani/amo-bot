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
 * The website's door for the shop's admins — its admin API, `/api/store/v1/{store}/admin/*`: the panels' own operations
 * of the shop's daily work (routes/api.php's `$operations`), worked by one of the shop's admins signed in on its website
 * with their bearer token (CustomerAuthMiddleware ran first: a session of this shop, its customer not banned). Shut by
 * default, asked in this order, each a 403 in the error shape: the customer must be an admin of the shop (`users.role`
 * admin) — whatever the website says, anyone else is told only that —; the website must let its admins in
 * (`staff_enabled`); while it asks them a strong sign-in (`staff_strong_sign_in`), the session must have been signed in
 * with Telegram, Google, or a password and its second step (CustomerSessions::isStrong() — a stronger way proven on it
 * since counts; the refusal's `WWW-Authenticate` names those ways); a way in must have been proven on it within
 * SIGN_IN_HOURS — a working day: an admin's token that leaked works for hours, not for the months a customer's session
 * lasts — (RecentSignInMiddleware's refusal); an operation the shop must grant (StaffGrant::ARGUMENT on its route) must
 * be granted (`staff_grants`), and it asks a recent sign-in too, the customers' own rule — as does an operation whose
 * route asks it without a grant (RECENT_SIGN_IN: approving a payment, which delivers a service). Then the request
 * carries the staff Principal — in the website's shop, its reader (CurrentPrincipal) — and every change it makes is
 * logged, with them on the line.
 */
final class StaffMiddleware implements MiddlewareInterface
{
    /** What anyone but the shop's admins is told — a customer among them, so in the words a customer reads. */
    public const NOT_STAFF = 'این بخش فقط برای پشتیبانی فروشگاه است.';
    public const STAFF_OFF = 'این وب‌سایت فعلا مدیران فروشگاه را راه نمی‌دهد.';
    public const SIGN_IN_STRONGLY = 'برای کار مدیریت، با تلگرام، گوگل یا رمز عبور همراه کد ورود دو مرحله‌ای وارد شوید.';
    public const NOT_GRANTED = 'فروشگاه این کار را به مدیران وب‌سایت نسپرده است.';

    /** What a session signed in with a password alone is asked (RFC 9470): a strong way, which POST /me/reauthenticate proves. */
    public const STRONG_CHALLENGE = 'Bearer error="insufficient_user_authentication", acr_values="telegram google password_2fa"';

    /** How long a way in proven on an admin's session lets it work the admin API: a working day. */
    public const SIGN_IN_HOURS = 12;

    /**
     * The route argument of an operation that asks a recent sign-in without a grant (a granted one asks it anyway): what
     * delivers something by the admin's word alone — approving a payment.
     */
    public const RECENT_SIGN_IN = 'staff_recent_sign_in';

    /** The methods a change is made with: the reads alone go unlogged. */
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

    /**
     * Until when the session's sign-in lets an admin work the admin API — SIGN_IN_HOURS after a way in was last proven on
     * it (CustomerSessions::provenUntil()); null once it no longer does.
     */
    public static function signedInUntil(CustomerSession $session): ?Carbon
    {
        return CustomerSessions::provenUntil($session, self::SIGN_IN_HOURS * 3600);
    }

    /**
     * A change the admin asked for, and how it was answered — done, or refused —, on a line of the log that names them
     * (Core\Logging\ActorProcessor); a read leaves none. A failure of the server's is the error handler's line, named too.
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

    /** A sign-in not proven lately enough: the customers' own refusal, its challenge asking a way in proven again (POST /me/reauthenticate). */
    private function signInAgain(): ResponseInterface
    {
        return $this->refuse(RecentSignInMiddleware::SIGN_IN_AGAIN)->withHeader('WWW-Authenticate', RecentSignInMiddleware::CHALLENGE);
    }
}
