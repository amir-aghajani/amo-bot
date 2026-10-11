<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Agency\Services\AgencyDirectory;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Auth\Principal;
use App\Modules\Catalog\Services\ServerSelector;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «حساب نمایندگی», the agent's own page: their bot and its state, the traffic it may still sell (and whether that is too
 * little to sell anything) with its ledger, their level, and their wallet and credit with the shop.
 */
final class AccountController extends ApiController
{
    public function __construct(
        private readonly AgencyDirectory $directory,
        private readonly TrafficPool $pool,
        private readonly ServerSelector $selector,
    ) {}

    /** GET /account — worked in their bot's shop, whose plans the traffic is held against. */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, ['account' => $this->directory->account(Principal::of($request)->shop), 'traffic_shortage' => $this->selector->shortage()]);
    }

    /** GET /account/traffic?page= — the traffic's lines, newest first. */
    public function traffic(Request $request, Response $response): Response
    {
        return $this->json($response, $this->pool->ledger(Principal::of($request)->shop, $this->pageRequest($request))->toArray('lines'));
    }
}
