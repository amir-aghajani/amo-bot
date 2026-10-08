<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Database\PageRequest;
use App\Core\Http\ApiController;
use App\Modules\Agency\DTO\AgencyTerms;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Agency\Services\AgencyDirectory;
use App\Modules\Auth\Principal;
use App\Modules\Users\Models\User;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «نمایندگان»: the agency program's numbers, the requests to become an agent — approved on a level or rejected — and the
 * agents, whose level and credit change, whose traffic the shop sets right, or whose agency ends. Each decision is
 * AgencyActions', which tells the customer; a request decided meanwhile (in the report group, a moment earlier) is a 422
 * on `status`. The program's rules are AgencySettingsController's, the levels their own list (AgencyLevelsController).
 * The owner's panel only, in the main bot's shop (the agents are its customers).
 */
final class AgencyController extends ApiController
{
    public function __construct(
        private readonly AgencyDirectory $directory,
        private readonly AgencyActions $actions,
    ) {}

    /** GET /agency */
    public function summary(Request $request, Response $response): Response
    {
        return $this->json($response, ['summary' => $this->directory->summary()]);
    }

    /** GET /agency/requests?status=&search=&page= */
    public function requests(Request $request, Response $response): Response
    {
        return $this->json($response, $this->directory->requests(PageRequest::fromQuery($request->getQueryParams()))->toArray('requests'));
    }

    /**
     * POST /agency/requests/{id}/approve — {level_id, credit_limit}
     *
     * @param array<string, string> $args
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $agencyRequest = $this->load(AgencyRequest::class, $args, ['user', 'level']);
        $terms = AgencyTerms::fromInput($this->input($request));

        return $this->request($response, $this->actions->approve($agencyRequest, Principal::of($request)->actor(), $terms->level, $terms->credit));
    }

    /**
     * POST /agency/requests/{id}/reject — {note?}: the customer reads the note.
     *
     * @param array<string, string> $args
     */
    public function reject(Request $request, Response $response, array $args): Response
    {
        $agencyRequest = $this->load(AgencyRequest::class, $args, ['user', 'level']);

        return $this->request($response, $this->actions->reject($agencyRequest, Principal::of($request)->actor(), Input::note($this->input($request), 'note')));
    }

    /** GET /agency/agents?level=&search=&page= */
    public function agents(Request $request, Response $response): Response
    {
        return $this->json($response, $this->directory->agents(PageRequest::fromQuery($request->getQueryParams()))->toArray('agents'));
    }

    /**
     * PUT /agency/agents/{id} — {level_id, credit_limit}: the agent's level and credit (the id is the customer's); the
     * answer says whether the agent was told (`delivery`) — null: nothing changed, nobody told.
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $agent = $this->load(User::class, $args);
        $delivery = $this->actions->change($agent, AgencyTerms::fromInput($this->input($request)));

        return $this->json($response, ['agent' => $this->directory->presentAgent($this->directory->agent($agent->id)), 'delivery' => $delivery?->value]);
    }

    /**
     * POST /agency/agents/{id}/revoke — {note?}: the agency ends; the customer reads the note.
     *
     * @param array<string, string> $args
     */
    public function revoke(Request $request, Response $response, array $args): Response
    {
        $this->actions->revoke($this->load(User::class, $args), Input::note($this->input($request), 'note'));

        return $this->noContent($response);
    }

    /**
     * GET /agency/agents/{id}/traffic?page= — the agent's traffic, line by line.
     *
     * @param array<string, string> $args
     */
    public function traffic(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, $this->directory->trafficLedger($this->load(User::class, $args), PageRequest::fromQuery($request->getQueryParams()))->toArray('lines'));
    }

    /**
     * POST /agency/agents/{id}/traffic — {gb, note?}: more traffic (or less, a negative gb), the note on the agent's ledger.
     *
     * @param array<string, string> $args
     */
    public function adjustTraffic(Request $request, Response $response, array $args): Response
    {
        $agent = $this->load(User::class, $args);
        $this->actions->adjustTraffic($agent, Principal::of($request)->actor(), $this->input($request));

        return $this->json($response, ['agent' => $this->directory->presentAgent($this->directory->agent($agent->id))]);
    }

    /** The request as it stands after the decision. */
    private function request(Response $response, AgencyRequest $agencyRequest): Response
    {
        return $this->json($response, ['request' => $this->directory->presentRequest($agencyRequest->load(['user', 'level']))]);
    }
}
