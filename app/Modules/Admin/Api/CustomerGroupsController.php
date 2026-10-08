<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Services\CustomerGroups;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The admin's groups of customers, a section of the users page: a unique name each, in the admin's order. Who is in
 * which is set on a customer's row (UsersController::groups); a deleted group's customers just leave it.
 */
final class CustomerGroupsController extends ApiController
{
    public function __construct(private readonly CustomerGroups $groups) {}

    /** GET /customer-groups */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['groups' => $this->groups->all()]);
    }

    /** POST /customer-groups — {name} */
    public function store(Request $request, Response $response): Response
    {
        return $this->json($response, ['group' => $this->groups->present($this->groups->create($this->input($request)))], 201);
    }

    /**
     * PUT /customer-groups/{id} — {name}
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $group = $this->groups->update($this->load(CustomerGroup::class, $args), $this->input($request));

        return $this->json($response, ['group' => $this->groups->present($group)]);
    }

    /** POST /customer-groups/reorder — {ids: [..]} */
    public function reorder(Request $request, Response $response): Response
    {
        $this->groups->reorder($this->reorderIds($request));

        return $this->json($response, ['groups' => $this->groups->all()]);
    }

    /**
     * DELETE /customer-groups/{id} — its customers just leave it
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->groups->delete($this->load(CustomerGroup::class, $args));

        return $this->noContent($response);
    }
}
