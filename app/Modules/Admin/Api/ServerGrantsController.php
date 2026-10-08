<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\GrantAudience;
use App\Modules\Subscriptions\Models\GrantPart;
use App\Modules\Subscriptions\Services\Grants;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «افزودن زمان و حجم» on a server's page: the grants' parts there — one issued here, or a «هدیه همگانی»'s —, a new grant
 * for this server, working on its part while the screen is open (a few seconds a request — the scheduler carries on
 * without it), and stopping it here.
 */
final class ServerGrantsController extends ApiController
{
    /** The seconds one request works on a part: the screen asks again while it is open. */
    private const BUDGET_SECONDS = 3;

    public function __construct(private readonly Grants $grants) {}

    /**
     * GET /servers/{id}/grants — the latest, the newest first, and whom a new one would reach
     *
     * @param array<string, string> $args
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $server = $this->load(Server::class, $args);

        return $this->json($response, ['grants' => $this->grants->historyOn($server), 'audience' => $this->grants->reach($server)]);
    }

    /**
     * POST /servers/{id}/grants — {days, traffic_gb, reason?, notify?, include_unstarted?}; the screen then works it through
     *
     * @param array<string, string> $args
     */
    public function store(Request $request, Response $response, array $args): Response
    {
        $server = $this->load(Server::class, $args);
        $grant = $this->grants->start(['audience' => GrantAudience::Server->value, 'server_id' => $server->id] + $this->input($request), Principal::of($request)->actor());
        $part = $grant->parts()->where('server_id', $server->id)->sole()->setRelation('grant', $grant);

        return $this->json($response, ['grant' => $this->grants->presentPart($part)], 201);
    }

    /**
     * POST /servers/{id}/grants/{grant}/run — a few seconds' work on the part, unless someone else is on it
     *
     * @param array<string, string> $args
     */
    public function run(Request $request, Response $response, array $args): Response
    {
        $part = $this->part($args);
        if ($part->isRunning()) {
            $this->grants->process($part, microtime(true) + self::BUDGET_SECONDS);
        }

        return $this->json($response, ['grant' => $this->grants->presentPart($part->refresh())]);
    }

    /**
     * POST /servers/{id}/grants/{grant}/cancel — the services it reached keep what they got; a «هدیه همگانی» goes on on its
     * other servers
     *
     * @param array<string, string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $part = $this->part($args);
        if (!$this->grants->cancelPart($part)) {
            throw ValidationException::on('status', 'این مورد دیگر در حال انجام نیست.');
        }

        return $this->json($response, ['grant' => $this->grants->presentPart($part->refresh())]);
    }

    /**
     * The grant's part on the server the route names, with its grant.
     *
     * @param array<string, string> $args
     */
    private function part(array $args): GrantPart
    {
        $part = GrantPart::query()->where('server_id', $this->load(Server::class, $args)->id)->with('grant')->findOrFail(Input::integerOf($args['grant'] ?? null) ?? 0);
        \assert($part instanceof GrantPart);

        return $part;
    }
}
