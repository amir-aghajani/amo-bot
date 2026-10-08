<?php

declare(strict_types=1);

namespace App\Modules\Store\Http;

use App\Core\Http\Json;
use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\Websites;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

/**
 * The Store API's door: the store key in the address (`{store}`) names a website, whose shop the request is worked in
 * (CurrentBot) with the website on the request (website()) — a key that names none switched on, or one whose shop's
 * bot does not run, is a 404 in the error shape, whatever the address under it.
 */
final class StoreMiddleware implements MiddlewareInterface
{
    /** What an address answers whose key opens no website. */
    public const CLOSED = 'این فروشگاه وب‌سایت فعالی ندارد.';

    /** The request attribute the website is put under. */
    private const ATTRIBUTE = 'website';

    public function __construct(
        private readonly Websites $websites,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = RouteContext::fromRequest($request)->getRoute()?->getArgument('store');
        $website = $key === null ? null : $this->websites->open($key);
        if ($website === null) {
            return Json::error($this->responses->createResponse(), self::CLOSED, 404);
        }

        return CurrentBot::run($website->shop(), static fn(): ResponseInterface => $handler->handle($request->withAttribute(self::ATTRIBUTE, $website)));
    }

    /** The website of a request that passed this middleware. */
    public static function website(ServerRequestInterface $request): Website
    {
        $website = $request->getAttribute(self::ATTRIBUTE);

        return $website instanceof Website ? $website : throw new \LogicException('The request did not pass the store middleware.');
    }
}
