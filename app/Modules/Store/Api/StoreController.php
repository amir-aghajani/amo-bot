<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Http\ApiController;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Services\Storefront;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** The Store API's `GET /`: the shop the store key names, as its website introduces it — what the site asks first. */
final class StoreController extends ApiController
{
    public function __construct(private readonly Storefront $storefront) {}

    /** GET / */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->storefront->present(StoreMiddleware::website($request)));
    }
}
