<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Rewards\Models\WheelPrize;
use App\Modules\Rewards\Services\WheelPrizes;
use App\Modules\Rewards\Services\WheelSpins;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** The lucky wheel («گردونه شانس», both panels): its prizes in the admin's order, and the spins customers made. */
final class WheelController extends ApiController
{
    public function __construct(
        private readonly WheelPrizes $prizes,
        private readonly WheelSpins $spins,
    ) {}

    /** GET /wheel/prizes */
    public function prizes(Request $request, Response $response): Response
    {
        return $this->json($response, ['prizes' => $this->prizes->all()]);
    }

    /** POST /wheel/prizes — {label, kind, amount?, plan_id?, weight, enabled?} */
    public function store(Request $request, Response $response): Response
    {
        return $this->json($response, ['prize' => WheelPrizes::present($this->prizes->create($this->input($request)))], 201);
    }

    /**
     * PUT /wheel/prizes/{id}
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $prize = $this->prizes->update($this->load(WheelPrize::class, $args), $this->input($request));

        return $this->json($response, ['prize' => WheelPrizes::present($prize)]);
    }

    /**
     * PATCH /wheel/prizes/{id} — {enabled}
     *
     * @param array<string, string> $args
     */
    public function patch(Request $request, Response $response, array $args): Response
    {
        $prize = $this->prizes->setEnabled($this->load(WheelPrize::class, $args), $this->switch($request, 'enabled'));

        return $this->json($response, ['prize' => WheelPrizes::present($prize)]);
    }

    /** POST /wheel/prizes/reorder — {ids: [..]} */
    public function reorder(Request $request, Response $response): Response
    {
        $this->prizes->reorder($this->reorderIds($request));

        return $this->json($response, ['prizes' => $this->prizes->all()]);
    }

    /**
     * DELETE /wheel/prizes/{id} — the spins that landed on it keep it as it was
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->prizes->delete($this->load(WheelPrize::class, $args));

        return $this->noContent($response);
    }

    /** GET /wheel/spins — ?user=&search=&page= */
    public function spins(Request $request, Response $response): Response
    {
        return $this->json($response, $this->spins->search($this->pageRequest($request))->toArray('spins'));
    }
}
