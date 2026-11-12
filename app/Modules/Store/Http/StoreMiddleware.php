<?php

declare(strict_types=1);

namespace App\Modules\Store\Http;

use App\Core\Http\Json;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Accounts\Http\CustomerAuthMiddleware;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Contracts\Door;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\MiniApps;
use App\Modules\Store\Services\Websites;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

/**
 * The Store API's door: the store key in the address (`{store}`) names a shop's open website or its open Telegram mini
 * app — each has a key of its own —, and the request runs in that shop (CurrentBot) with the door attached (door()). A key
 * of neither is a 404, whatever the address under it.
 *
 * Most of the API is the same through either door. What only one of them has is a route group behind WebsiteOnlyMiddleware
 * (the website's ways in, its captcha, its admins' API) or MiniAppOnlyMiddleware (the launch data's sign-in, the logo,
 * the fingerprint secret): through the other door those addresses are the router's own 404, as if they were not there.
 *
 * A mini app switched off still opens for a panel's preview of it (CustomerSessions::openPreview()): a request carrying
 * the token of a live preview session of its shop — every request the preview makes, `GET /` among them — finds it as
 * if it were on, so the owner tries it before customers see it; and only for what a preview may ask
 * (CustomerAuthMiddleware::previewMayAsk(); its PREVIEW otherwise, whatever the route). Anyone else still gets CLOSED.
 */
final class StoreMiddleware implements MiddlewareInterface
{
    /**
     * What an address answers whose key opens no website and no mini app — worded for either door's customers: a
     * website switched off, a mini app switched off, an agent's shop closed, a key replaced or never made.
     */
    public const CLOSED = 'این فروشگاه فعلا در دسترس نیست.';

    /** The request attribute the door is put under: the one the customer's guard reads its sign-in site from. */
    private const ATTRIBUTE = Customer::SITE;

    public function __construct(
        private readonly Websites $websites,
        private readonly MiniApps $miniApps,
        private readonly CustomerSessions $sessions,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = RouteContext::fromRequest($request)->getRoute()?->getArgument('store');
        $door = $key === null ? null : $this->websites->open($key) ?? $this->miniApps->open($key);
        if ($door === null && $key !== null) {
            $door = $this->previewed($key, $request);
            if ($door !== null && !CustomerAuthMiddleware::previewMayAsk($request)) {
                return Json::error($this->responses->createResponse(), CustomerAuthMiddleware::PREVIEW, 403);
            }
        }
        if ($door === null) {
            return Json::error($this->responses->createResponse(), self::CLOSED, 404);
        }

        return CurrentBot::run($door->shop(), static fn(): ResponseInterface => $handler->handle($request->withAttribute(self::ATTRIBUTE, $door)));
    }

    /** The door of a request that passed this middleware: the website or the mini app its key opened. */
    public static function door(ServerRequestInterface $request): Door
    {
        $door = $request->getAttribute(self::ATTRIBUTE);

        return $door instanceof Door ? $door : throw new \LogicException('The request did not pass the store middleware.');
    }

    /** The website of a request on a website-only route (WebsiteOnlyMiddleware guards the group). */
    public static function website(ServerRequestInterface $request): Website
    {
        $door = self::door($request);

        return $door instanceof Website ? $door : throw new \LogicException('A website-only route was reached through the mini app.');
    }

    /** The mini app of a request on a mini-app-only route (MiniAppOnlyMiddleware guards the group). */
    public static function miniApp(ServerRequestInterface $request): MiniApp
    {
        $door = self::door($request);

        return $door instanceof MiniApp ? $door : throw new \LogicException('A mini-app-only route was reached through the website.');
    }

    /**
     * The switched-off mini app under `$key`, for a request that carries the token of a live preview session of its shop;
     * null otherwise — no token, another kind of session, another shop's, one ended, or no such app (or its shop closed).
     */
    private function previewed(string $key, ServerRequestInterface $request): ?MiniApp
    {
        $token = CustomerAuthMiddleware::bearer($request);
        $app = $token === null ? null : $this->miniApps->previewable($key);
        $session = $app === null || $token === null ? null : CurrentBot::run($app->shop(), fn(): ?CustomerSession => $this->sessions->find($token));

        return $session !== null && CustomerSessions::isPreview($session) ? $app : null;
    }
}
