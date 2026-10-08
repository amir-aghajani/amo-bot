<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Agency\Models\AgencyLevel;
use App\Modules\Agency\Services\AgencyLevels;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The agents' levels, a section of the agents page: a unique name and the price per GB its agents buy traffic at, in the
 * admin's order. A level agents are on is not deleted (409, LevelInUseException).
 */
final class AgencyLevelsController extends ApiController
{
    public function __construct(private readonly AgencyLevels $levels) {}

    /** GET /agency/levels */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['levels' => $this->levels->all()]);
    }

    /** POST /agency/levels — {name, price_per_gb} */
    public function store(Request $request, Response $response): Response
    {
        return $this->json($response, ['level' => $this->levels->present($this->levels->create($this->input($request)))], 201);
    }

    /**
     * PUT /agency/levels/{id} — {name, price_per_gb}
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $level = $this->levels->update($this->load(AgencyLevel::class, $args), $this->input($request));

        return $this->json($response, ['level' => $this->levels->present($level)]);
    }

    /** POST /agency/levels/reorder — {ids: [..]} */
    public function reorder(Request $request, Response $response): Response
    {
        $this->levels->reorder($this->reorderIds($request));

        return $this->json($response, ['levels' => $this->levels->all()]);
    }

    /**
     * DELETE /agency/levels/{id} — refused while agents are on it.
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->levels->delete($this->load(AgencyLevel::class, $args));

        return $this->noContent($response);
    }
}
