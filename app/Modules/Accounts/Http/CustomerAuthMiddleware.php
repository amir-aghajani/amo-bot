<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http;

use App\Core\Http\Json;
use App\Modules\Accounts\Contracts\SignInSite;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Users\Services\Customers;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

/**
 * Guards a website customer's own routes: the bearer token must open a live session of the request's shop
 * (StoreMiddleware ran first) — otherwise a 401 with `WWW-Authenticate: Bearer` — and its customer must not be banned
 * (403). The request then carries the Customer; the session's use and the customer's visit are recorded as they fall due.
 *
 * A panel's preview of the mini app (CustomerSessions::isPreview()) opens its customer's account through the mini app's
 * key alone — under a website's it opens nothing —, and only to read: any other request is a 403 (PREVIEW) before
 * anything runs, but for a route marked PREVIEW_READS. Nothing of it is recorded: neither the session's use nor a visit
 * of the customer's.
 */
final class CustomerAuthMiddleware implements MiddlewareInterface
{
    public const SIGNED_OUT = 'برای دسترسی باید وارد شوید.';

    /** What a preview answers a request that would change or send something. */
    public const PREVIEW = 'این پیش‌نمایش است؛ در آن چیزی ذخیره یا فرستاده نمی‌شود.';

    /**
     * The argument of a route that is no GET but only works something out — a price, nothing kept —, which a page asks
     * by itself, so a preview may ask it too. Its value names the body field that would make it keep something when sent
     * (a discount code: one that does not hold spends one of the customer's tries); sent, it is refused all the same.
     */
    public const PREVIEW_READS = 'preview_reads';

    /** The methods that read: all a preview may ask. */
    private const READS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly CustomerSessions $sessions,
        private readonly Customers $customers,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->admit($request, $handler, false);
    }

    /**
     * OptionalCustomerMiddleware's way through, for a request that carries a token: as process(), but a preview's token
     * under a website's key — which opens nothing there — goes on as a guest's request.
     */
    public function processOptional(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->admit($request, $handler, true);
    }

    /** The token of the request's `Authorization: Bearer …`; null for none. */
    public static function bearer(ServerRequestInterface $request): ?string
    {
        return preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $bearer) === 1 ? $bearer[1] : null;
    }

    /**
     * Whether a preview may ask the request: it reads (GET, HEAD, OPTIONS), or it only works something out (a route marked
     * PREVIEW_READS, without the field it names).
     */
    public static function previewMayAsk(ServerRequestInterface $request): bool
    {
        return in_array(strtoupper($request->getMethod()), self::READS, true) || self::onlyWorksOut($request);
    }

    private function admit(ServerRequestInterface $request, RequestHandlerInterface $handler, bool $optional): ResponseInterface
    {
        $token = self::bearer($request);
        $session = $token === null ? null : $this->sessions->find($token);
        $preview = $session !== null && CustomerSessions::isPreview($session);
        if ($preview && !self::throughMiniApp($request)) {
            if ($optional) {
                return $handler->handle($request);
            }
            $session = null;
        }
        if ($session === null) {
            return Json::error($this->responses->createResponse(), self::SIGNED_OUT, 401)->withHeader('WWW-Authenticate', 'Bearer');
        }
        if ($preview && !self::previewMayAsk($request)) {
            return Json::error($this->responses->createResponse(), self::PREVIEW, 403);
        }

        $user = $session->user;
        if ($user->isBanned()) {
            return Json::error($this->responses->createResponse(), SignInRefusedException::BANNED, 403);
        }

        if (!$preview) {
            $this->sessions->touch($session);
            $this->customers->seen($user);
        }

        return $handler->handle($request->withAttribute(Customer::ATTRIBUTE, new Customer($session, $user)));
    }

    /**
     * Whether the request is to a route marked PREVIEW_READS and leaves out — or sends blank — the field it names: it
     * only works something out, keeping nothing.
     */
    private static function onlyWorksOut(ServerRequestInterface $request): bool
    {
        $field = RouteContext::fromRequest($request)->getRoute()?->getArgument(self::PREVIEW_READS);
        if ($field === null) {
            return false;
        }
        $body = $request->getParsedBody();
        $value = is_array($body) ? $body[$field] ?? null : null;

        return $value === null || (is_scalar($value) && trim((string) $value) === '');
    }

    /** Whether the request came through the shop's mini app (the site StoreMiddleware put on it). */
    private static function throughMiniApp(ServerRequestInterface $request): bool
    {
        $site = $request->getAttribute(Customer::SITE);

        return $site instanceof SignInSite && $site->isMiniApp();
    }
}
