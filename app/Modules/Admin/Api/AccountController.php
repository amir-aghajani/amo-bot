<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Agency\Services\AgencyDirectory;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Auth\Principal;
use App\Modules\Catalog\Services\ServerSelector;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «حساب نمایندگی», the agent's own page: their bot and its state, the traffic it may still sell (and whether that is too
 * little to sell anything) with its ledger, their level, their wallet and credit with the shop, and «درخواست تمدید
 * نمایندگی» — their agency's end moved on, once the owner approves it.
 */
final class AccountController extends ApiController
{
    public function __construct(
        private readonly AgencyDirectory $directory,
        private readonly AgencyActions $actions,
        private readonly TrafficPool $pool,
        private readonly ServerSelector $selector,
    ) {}

    /** GET /account — worked in their bot's shop, whose plans the traffic is held against. */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, ['account' => $this->directory->account(Principal::of($request)->shop), 'traffic_shortage' => $this->selector->shortage()]);
    }

    /**
     * POST /account/renewal — «درخواست تمدید نمایندگی»: the agent asks for their agency's end to be moved on; the owner
     * decides (AgencyActions::requestRenewal()). 201 with the account as GET /account answers it.
     *
     * @throws ValidationException 422 on `status`: one waits already, or their agency has no end to move on
     */
    public function renewal(Request $request, Response $response): Response
    {
        $shop = Principal::of($request)->shop;
        $agent = $shop->agent ?? throw new \LogicException("Bot #{$shop->id} has no agent.");
        if ($this->actions->requestRenewal($agent) === null) {
            throw ValidationException::on('status', AgencyActions::pendingRenewal($agent) !== null ? AgencyActions::RENEWAL_WAITING : AgencyActions::RENEWAL_NOT_NEEDED);
        }

        return $this->json($response, ['account' => $this->directory->account($shop), 'traffic_shortage' => $this->selector->shortage()], 201);
    }

    /** GET /account/traffic?page= — the traffic's lines, newest first. */
    public function traffic(Request $request, Response $response): Response
    {
        return $this->json($response, $this->pool->ledger(Principal::of($request)->shop, $this->pageRequest($request))->toArray('lines'));
    }
}
