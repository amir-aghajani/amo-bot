<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Http\ApiController;
use App\Modules\Store\Exceptions\NoLogoException;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Services\MiniApps;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The mini app's logo (`app.logo_url` of `GET /`), only through its own key (MiniAppOnlyMiddleware). No customer's: any
 * cache may keep it a few minutes, so a logo replaced shows in the app within them.
 */
final class MiniAppLogoController extends ApiController
{
    private const CACHE = 'public, max-age=300';

    public function __construct(private readonly MiniApps $miniApps) {}

    /**
     * GET /app/logo
     *
     * @throws NoLogoException 404 while the app has none
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $logo = $this->miniApps->logo(StoreMiddleware::miniApp($request)) ?? throw new NoLogoException();

        return $this->bytes($response, $logo['body'], $logo['mime'], self::CACHE);
    }
}
