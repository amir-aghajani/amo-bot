<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Catalog\Services\PlanCategoryService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** The plan categories screen (PlanCategoryService): the list, add, rename, the switch, the order, delete. */
final class PlanCategoriesController extends ApiController
{
    public function __construct(private readonly PlanCategoryService $categories) {}

    /** GET /plans/categories */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['categories' => $this->categories->all()]);
    }

    /** POST /plans/categories */
    public function store(Request $request, Response $response): Response
    {
        return $this->present($response, $this->categories->create($this->input($request)), 201);
    }

    /**
     * PUT /plans/categories/{id}
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        return $this->present($response, $this->categories->update($this->load(PlanCategory::class, $args), $this->input($request)));
    }

    /**
     * PATCH /plans/categories/{id} — {is_active: bool}
     *
     * @param array<string, string> $args
     */
    public function patch(Request $request, Response $response, array $args): Response
    {
        return $this->present($response, $this->categories->setActive($this->load(PlanCategory::class, $args), $this->switch($request, 'is_active')));
    }

    /** POST /plans/categories/reorder — {ids: [..]} */
    public function reorder(Request $request, Response $response): Response
    {
        $this->categories->reorder($this->reorderIds($request));

        return $this->json($response, ['categories' => $this->categories->all()]);
    }

    /**
     * DELETE /plans/categories/{id} — its plans stay, uncategorised.
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->categories->delete($this->load(PlanCategory::class, $args));

        return $this->noContent($response);
    }

    private function present(Response $response, PlanCategory $category, int $status = 200): Response
    {
        return $this->json($response, ['category' => $this->categories->present($category)], $status);
    }
}
