<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Subscriptions\Models\Grant;
use App\Modules\Subscriptions\Services\Grants;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «هدیه همگانی» on the broadcasts page: the latest grants — the servers' pages' too — with each server's part, whom a new
 * one would reach, a new one, working on it while the screen is open (a few seconds a request — the scheduler carries on
 * without it), and stopping it.
 */
final class MassGrantsController extends ApiController
{
    /** The seconds one request works on a grant: the screen asks again while it is open. */
    private const BUDGET_SECONDS = 3;

    public function __construct(private readonly Grants $grants) {}

    /** GET /mass-grants — the latest, the newest first, and whom a new one would reach */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['grants' => $this->grants->history(), 'audience' => $this->grants->audience()]);
    }

    /** POST /mass-grants — {days, traffic_gb, reason?, notify?, include_unstarted?, audience, server_id?} */
    public function store(Request $request, Response $response): Response
    {
        $grant = $this->grants->start($this->input($request), Principal::of($request)->actor());

        return $this->present($response, $grant, 201);
    }

    /**
     * POST /mass-grants/{id}/run — a few seconds' work on its parts, unless someone else is on them
     *
     * @param array<string, string> $args
     */
    public function run(Request $request, Response $response, array $args): Response
    {
        $grant = $this->load(Grant::class, $args);
        $this->grants->run($grant, microtime(true) + self::BUDGET_SECONDS);

        return $this->present($response, $grant);
    }

    /**
     * POST /mass-grants/{id}/cancel — every part still going stops; what was given stays
     *
     * @param array<string, string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $grant = $this->load(Grant::class, $args);
        if (!$this->grants->cancel($grant)) {
            throw ValidationException::on('status', 'این هدیه دیگر در حال انجام نیست.');
        }

        return $this->present($response, $grant);
    }

    private function present(Response $response, Grant $grant, int $status = 200): Response
    {
        return $this->json($response, ['grant' => $this->grants->present($grant->load('parts.server'))], $status);
    }
}
