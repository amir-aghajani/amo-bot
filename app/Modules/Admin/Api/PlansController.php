<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\PlanService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** The plans screen (PlanService): the list, the form's options, add, edit, the switch, a copy, the order, delete. */
final class PlansController extends ApiController
{
    public function __construct(private readonly PlanService $plans) {}

    /** GET /plans */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['plans' => $this->plans->all()]);
    }

    /** GET /plans/options — the servers and inbounds the form builds entries from, and the categories. */
    public function options(Request $request, Response $response): Response
    {
        return $this->json($response, $this->plans->options());
    }

    /** POST /plans */
    public function store(Request $request, Response $response): Response
    {
        return $this->present($response, $this->plans->create($this->input($request)), 201);
    }

    /**
     * PUT /plans/{id}
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        return $this->present($response, $this->plans->update($this->load(Plan::class, $args), $this->input($request))->refresh());
    }

    /**
     * PATCH /plans/{id} — {is_active: bool}: the switch in the list.
     *
     * @param array<string, string> $args
     */
    public function patch(Request $request, Response $response, array $args): Response
    {
        return $this->present($response, $this->plans->setActive($this->load(Plan::class, $args), $this->switch($request, 'is_active')));
    }

    /**
     * POST /plans/{id}/duplicate
     *
     * @param array<string, string> $args
     */
    public function duplicate(Request $request, Response $response, array $args): Response
    {
        return $this->present($response, $this->plans->duplicate($this->load(Plan::class, $args)), 201);
    }

    /** POST /plans/reorder — {ids: [..]} in the new display order. */
    public function reorder(Request $request, Response $response): Response
    {
        $this->plans->reorder($this->reorderIds($request));

        return $this->json($response, ['plans' => $this->plans->all()]);
    }

    /**
     * DELETE /plans/{id} — refused (409) once an order or a service points at it.
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->plans->delete($this->load(Plan::class, $args));

        return $this->noContent($response);
    }

    private function present(Response $response, Plan $plan, int $status = 200): Response
    {
        return $this->json($response, ['plan' => $this->plans->present($plan)], $status);
    }
}
