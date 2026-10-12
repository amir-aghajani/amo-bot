<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Services\TestAccountDirectory;
use App\Modules\Subscriptions\Services\TestServers;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Free test accounts («اکانت تست»): the tests a shop gave and every server's test terms (both panels), and a server's
 * own terms — the installation's, so the owner's alone.
 */
final class TestAccountsController extends ApiController
{
    public function __construct(
        private readonly TestAccountDirectory $tests,
        private readonly TestServers $servers,
    ) {}

    /** GET /tests — ?status=&user=&search=&page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->tests->search($this->pageRequest($request))->toArray('tests'));
    }

    /** GET /tests/servers — every server's test terms, and whether it would give a test in this shop now. */
    public function servers(Request $request, Response $response): Response
    {
        return $this->json($response, ['servers' => $this->tests->servers()]);
    }

    /**
     * GET /servers/{id}/test-account (the owner's)
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['test_account' => TestServers::present($this->load(Server::class, $args))]);
    }

    /**
     * PUT /servers/{id}/test-account (the owner's) — {test_enabled, test_traffic_mb, test_duration_hours, test_on_hold}
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $server = $this->servers->update($this->load(Server::class, $args), $this->input($request));

        return $this->json($response, ['test_account' => TestServers::present($server)]);
    }
}
