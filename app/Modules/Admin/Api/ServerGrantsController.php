<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\GrantPart;
use App\Modules\Subscriptions\Services\Grants;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «افزودن زمان و حجم» on a server's page: the grant parts on this server (its own grants' and «هدیه همگانی»'s), a new grant
 * for it, and stopping one here. While the screen is open it works the part a few seconds per request; the scheduler
 * carries on without it.
 */
final class ServerGrantsController extends ApiController
{
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
        $part = $this->grants->startOn($this->load(Server::class, $args), $this->input($request), Principal::of($request)->actor());

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
        $this->grants->runPart($part);

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
        $this->grants->cancelPart($part);

        return $this->json($response, ['grant' => $this->grants->presentPart($part->refresh())]);
    }

    /**
     * The grant's part on the server the route names, with its grant.
     *
     * @param array<string, string> $args
     */
    private function part(array $args): GrantPart
    {
        $part = GrantPart::query()->where('server_id', $this->load(Server::class, $args)->id)->with('grant')->findOrFail($this->routeId($args, 'grant'));
        \assert($part instanceof GrantPart);

        return $part;
    }
}
