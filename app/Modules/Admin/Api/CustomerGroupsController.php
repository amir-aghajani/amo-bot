<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Auth\Principal;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Services\CustomerGroups;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The admin's customer groups (a section of the users page), in the admin's order. Membership is set on a customer
 * (UsersController::groups); deleting a group just takes its customers out of it.
 */
final class CustomerGroupsController extends ApiController
{
    public function __construct(private readonly CustomerGroups $groups) {}

    /** GET /customer-groups */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['groups' => $this->groups->all()]);
    }

    /** POST /customer-groups — {name, agent_pricing?} */
    public function store(Request $request, Response $response): Response
    {
        return $this->json($response, ['group' => $this->groups->present($this->groups->create($this->input($request)))], 201);
    }

    /**
     * PUT /customer-groups/{id} — {name, agent_pricing?}: a switch left out keeps its value
     *
     * @param array<string, string> $args
     * @throws ActorRefusedException 403 from the website, «همکار» switched on with an admin in the group
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $group = $this->groups->update($this->load(CustomerGroup::class, $args), Principal::of($request)->actor(), $this->input($request));

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
