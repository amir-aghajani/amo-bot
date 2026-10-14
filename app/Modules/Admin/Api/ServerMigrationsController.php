<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\ServerMigration;
use App\Modules\Subscriptions\Services\ServerMigrations;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «انتقال سرویس‌ها» on a server's page, the owner's: the latest moves of its services to another server, what a new one
 * would move, starting one, its failures, pausing, resuming, cancelling and switching it to rescue — and the clients
 * moves left on its panel, removed now or forgotten. While the page is open it works the job a few seconds per request;
 * the scheduler carries on without it.
 */
final class ServerMigrationsController extends ApiController
{
    public function __construct(private readonly ServerMigrations $migrations) {}

    /**
     * GET /servers/{id}/migrations — the latest, newest first, and the clients moves left on its panel
     *
     * @param array<string, string> $args
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $server = $this->load(Server::class, $args);

        return $this->json($response, ['migrations' => $this->migrations->historyOn($server), 'leftovers' => $this->migrations->leftoversOn($server)]);
    }

    /**
     * POST /servers/{id}/migrations/preview — {target_server_id?, include_disabled?, plan_ids?}: what a job would move
     *
     * @param array<string, string> $args
     */
    public function preview(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, $this->migrations->preview($this->load(Server::class, $args), $this->input($request)));
    }

    /**
     * POST /servers/{id}/migrations — {target_server_id, mode, include_disabled?, notify?, plan_ids?}; the page then works it
     *
     * @param array<string, string> $args
     */
    public function store(Request $request, Response $response, array $args): Response
    {
        $job = $this->migrations->start($this->load(Server::class, $args), $this->input($request), Principal::of($request)->actor());

        return $this->json($response, ['migration' => $this->migrations->present($job)], 201);
    }

    /**
     * GET /servers/{id}/migrations/{migration} — the job, with the services it could not move
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $job = $this->job($args);

        return $this->json($response, ['migration' => $this->migrations->present($job), 'failures' => $this->migrations->failures($job)]);
    }

    /**
     * POST /servers/{id}/migrations/{migration}/run — a few seconds' work on the job, unless someone else is on it
     *
     * @param array<string, string> $args
     */
    public function run(Request $request, Response $response, array $args): Response
    {
        $job = $this->job($args);
        $this->migrations->run($job);

        return $this->answer($response, $job);
    }

    /**
     * POST /servers/{id}/migrations/{migration}/pause
     *
     * @param array<string, string> $args
     */
    public function pause(Request $request, Response $response, array $args): Response
    {
        $job = $this->job($args);
        $this->migrations->pause($job);

        return $this->answer($response, $job);
    }

    /**
     * POST /servers/{id}/migrations/{migration}/resume
     *
     * @param array<string, string> $args
     */
    public function resume(Request $request, Response $response, array $args): Response
    {
        $job = $this->job($args);
        $this->migrations->resume($job);

        return $this->answer($response, $job);
    }

    /**
     * POST /servers/{id}/migrations/{migration}/cancel — the services moved stay moved
     *
     * @param array<string, string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $job = $this->job($args);
        $this->migrations->cancel($job);

        return $this->answer($response, $job);
    }

    /**
     * POST /servers/{id}/migrations/{migration}/rescue — a migration goes on without asking the source
     *
     * @param array<string, string> $args
     */
    public function rescue(Request $request, Response $response, array $args): Response
    {
        $job = $this->job($args);
        $this->migrations->toRescue($job);

        return $this->answer($response, $job);
    }

    /**
     * POST /servers/{id}/leftovers/clean — the clients moves left on its panel, removed for a few seconds
     *
     * @param array<string, string> $args
     */
    public function clean(Request $request, Response $response, array $args): Response
    {
        $server = $this->load(Server::class, $args);
        $this->migrations->cleanNow($server);

        return $this->json($response, ['leftovers' => $this->migrations->leftoversOn($server)]);
    }

    /**
     * DELETE /servers/{id}/leftovers — forget them: the panel is gone for good
     *
     * @param array<string, string> $args
     */
    public function forget(Request $request, Response $response, array $args): Response
    {
        $server = $this->load(Server::class, $args);
        $this->migrations->forgetLeftovers($server);

        return $this->json($response, ['leftovers' => $this->migrations->leftoversOn($server)]);
    }

    /**
     * The job from the server the route names, with its servers.
     *
     * @param array<string, string> $args
     */
    private function job(array $args): ServerMigration
    {
        $job = ServerMigration::query()->where('source_server_id', $this->load(Server::class, $args)->id)->with(['source', 'target'])->findOrFail($this->routeId($args, 'migration'));
        \assert($job instanceof ServerMigration);

        return $job;
    }

    /** The job as it is now. */
    private function answer(Response $response, ServerMigration $job): Response
    {
        return $this->json($response, ['migration' => $this->migrations->present($job->refresh())]);
    }
}
