<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Agency\DTO\AgencyTerms;
use App\Modules\Agency\DTO\SellingTerms;
use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Agency\Services\AgencyDirectory;
use App\Modules\Agency\Services\AgencyStanding;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Auth\Principal;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Services\AgentRestrictions;
use App\Modules\Store\Http\IdempotencyKey;
use App\Modules\Users\Models\User;
use App\Support\Input;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «نمایندگان» (the owner's panel, in the main bot's shop, whose customers the agents are): the program's numbers, the
 * requests to become an agent (approved on a level, or rejected) and agents' renewal requests (approved with an end), and
 * the agents — terms changed (an end and a monthly
 * minimum among them), traffic set right, selling suspended or resumed, servers and plans kept from their shop, agency
 * ended. Each decision is AgencyActions' or AgencyStanding's, which tell the customer; a request decided meanwhile (in
 * the report group) is a 422 on `status`.
 */
final class AgencyController extends ApiController
{
    public function __construct(
        private readonly AgencyDirectory $directory,
        private readonly AgencyActions $actions,
        private readonly AgencyStanding $standing,
        private readonly AgentRestrictions $restrictions,
    ) {}

    /** GET /agency */
    public function summary(Request $request, Response $response): Response
    {
        return $this->json($response, ['summary' => $this->directory->summary()]);
    }

    /** GET /agency/requests?status=&search=&page= */
    public function requests(Request $request, Response $response): Response
    {
        return $this->json($response, $this->directory->requests($this->pageRequest($request))->toArray('requests'));
    }

    /**
     * GET /agency/requests/{id} — one request as the list shows it, as it stands now (the bot's card reads it before a
     * decision).
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->request($response, $this->load(AgencyRequest::class, $args));
    }

    /**
     * POST /agency/requests/{id}/approve — to become an agent {level_id, credit_limit, expires_at?, monthly_minimum?}: the
     * end and the minimum left out are the program's; a renewal {expires_at?}: the agent's new end — blank or null for none
     * at all, left out for the program's term on from their end (AgencyActions::termEnd()).
     *
     * @param array<string, string> $args
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $agencyRequest = $this->load(AgencyRequest::class, $args, ['user.ownBot', 'level']);
        $input = $this->input($request);
        $actor = Principal::of($request)->actor();
        if ($agencyRequest->isRenewal()) {
            // Decided meanwhile is said as such, before the term's end is worked out for it.
            if ($agencyRequest->status !== AgencyRequestStatus::Pending) {
                throw ValidationException::on('status', AgencyActions::decided($agencyRequest));
            }

            return $this->request($response, $this->actions->renew($agencyRequest, $actor, array_key_exists('expires_at', $input) ? self::end($input) : $this->actions->termEnd($agencyRequest)));
        }
        $terms = AgencyTerms::fromInput($input, $this->standing->defaults());

        return $this->request($response, $this->actions->approve($agencyRequest, $actor, $terms->level, $terms->credit, $terms->selling));
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
        return $this->json($response, $this->directory->agents($this->pageRequest($request))->toArray('agents'));
    }

    /**
     * PUT /agency/agents/{id} — {level_id, credit_limit, expires_at?, monthly_minimum?}: the agent's level and credit
     * (the id is the customer's) and their terms of selling (each left out kept); the answer says whether the agent was
     * told (`delivery`) — null: nothing changed, nobody told.
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $agent = $this->load(User::class, $args);
        self::notOwn($request, $agent);
        $bot = $agent->ownBot;
        $delivery = $this->actions->change($agent, AgencyTerms::fromInput($this->input($request), $bot === null ? null : SellingTerms::of($bot)));

        return $this->json($response, ['agent' => $this->directory->presentAgent($this->directory->agent($agent->id)), 'delivery' => $delivery?->value]);
    }

    /**
     * POST /agency/agents/{id}/revoke — {note?}: the agency ends; the customer reads the note.
     *
     * @param array<string, string> $args
     */
    public function revoke(Request $request, Response $response, array $args): Response
    {
        $agent = $this->load(User::class, $args);
        self::notOwn($request, $agent);
        $this->actions->revoke($agent, Input::note($this->input($request), 'note'));

        return $this->noContent($response);
    }

    /**
     * GET /agency/agents/{id}/traffic?page= — the agent's traffic, line by line.
     *
     * @param array<string, string> $args
     */
    public function traffic(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, $this->directory->trafficLedger($this->load(User::class, $args), $this->pageRequest($request))->toArray('lines'));
    }

    /**
     * POST /agency/agents/{id}/traffic — {gb, note?}: more traffic (or less, a negative gb), the note on the agent's ledger;
     * with an Idempotency-Key if the caller sends one, the same request again answered by the line the key wrote.
     *
     * @param array<string, string> $args
     */
    public function adjustTraffic(Request $request, Response $response, array $args): Response
    {
        $agent = $this->load(User::class, $args);
        self::notOwn($request, $agent);
        $this->actions->adjustTraffic($agent, Principal::of($request)->actor(), $this->input($request), IdempotencyKey::optional($request));

        return $this->json($response, ['agent' => $this->directory->presentAgent($this->directory->agent($agent->id))]);
    }

    /**
     * POST /agency/agents/{id}/suspend — {note?}: the agent's selling stops until the owner resumes it; the agent reads
     * the note.
     *
     * @param array<string, string> $args
     */
    public function suspend(Request $request, Response $response, array $args): Response
    {
        $agent = $this->load(User::class, $args);
        self::notOwn($request, $agent);
        $this->standing->suspendByHand($this->shopOf($agent), Input::note($this->input($request), 'note'));

        return $this->agent($response, $agent);
    }

    /**
     * POST /agency/agents/{id}/resume — the agent's selling resumed, whatever stopped it — an end passed aside, which is
     * extended instead (PUT /agency/agents/{id}).
     *
     * @param array<string, string> $args
     */
    public function resume(Request $request, Response $response, array $args): Response
    {
        $agent = $this->load(User::class, $args);
        self::notOwn($request, $agent);
        $this->standing->resume($this->shopOf($agent));

        return $this->agent($response, $agent);
    }

    /**
     * GET /agency/agents/{id}/hidden — every server and every plan of the agent's shop, each with whether the owner keeps
     * it from the shop.
     *
     * @param array<string, string> $args
     */
    public function hidden(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['hidden' => $this->restrictions->present($this->shopOf($this->load(User::class, $args)))]);
    }

    /**
     * PUT /agency/agents/{id}/hidden — {server_ids, plan_ids}: exactly these kept from the agent's shop.
     *
     * @param array<string, string> $args
     */
    public function hide(Request $request, Response $response, array $args): Response
    {
        $agent = $this->load(User::class, $args);
        self::notOwn($request, $agent);
        $bot = $this->shopOf($agent);
        $this->restrictions->save($bot, $this->input($request));

        return $this->json($response, ['hidden' => $this->restrictions->present($bot), 'agent' => $this->directory->presentAgent($this->directory->agent($agent->id))]);
    }

    /**
     * Refuses one of the shop's admins who is this agent too a change of their own agency (from the bot's management,
     * working with the owner's seat): another admin, or the owner, makes it.
     *
     * @throws ActorRefusedException 403
     */
    private static function notOwn(Request $request, User $agent): void
    {
        if (Principal::of($request)->actor()->is($agent->id)) {
            throw ActorRefusedException::ownAgency();
        }
    }

    /** The request as it stands after the decision. */
    private function request(Response $response, AgencyRequest $agencyRequest): Response
    {
        return $this->json($response, ['request' => $this->directory->presentRequest($agencyRequest->load(['user.ownBot', 'level']))]);
    }

    /**
     * A renewal's end as the panel sends it (`expires_at`, read as the agents page's: SellingTerms::fromInput()): null
     * for none at all.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `expires_at`
     */
    private static function end(array $input): ?Carbon
    {
        $errors = [];
        $terms = SellingTerms::fromInput(['expires_at' => $input['expires_at']], new SellingTerms(null, null), $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $terms?->expiresAt;
    }

    /** The agent as the list shows them after a decision. */
    private function agent(Response $response, User $agent): Response
    {
        return $this->json($response, ['agent' => $this->directory->presentAgent($this->directory->agent($agent->id))]);
    }

    /**
     * The agent's shop, for a decision about it.
     *
     * @throws ValidationException 422 on `status`: no agent, or no shop yet
     */
    private function shopOf(User $agent): Bot
    {
        if (!$agent->isAgent()) {
            throw ValidationException::on('status', $agent->ownBot()->exists() ? AgencyActions::AGENCY_ENDED : AgencyActions::NOT_AGENT);
        }

        return $agent->ownBot ?? throw ValidationException::on('status', AgencyStanding::NO_SHOP);
    }
}
