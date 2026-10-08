<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Providers\Services\ServerService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Servers = panel connections, the owner's. A server is added and changed by its connector's form (ServerService);
 * credentials are stored encrypted and never leave the API again — a server's form answers whether one is kept.
 */
final class ServersController extends ApiController
{
    public function __construct(private readonly ServerService $servers) {}

    /** GET /servers/drivers — the connector picker, each connector with a server's form on it. */
    public function drivers(Request $request, Response $response): Response
    {
        return $this->json($response, ['drivers' => $this->servers->drivers()]);
    }

    /** GET /servers */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['servers' => $this->servers->all()]);
    }

    /**
     * GET /servers/{id}
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->detail($response, $this->load(Server::class, $args));
    }

    /** POST /servers — check its connector's form, store, then run a first check. */
    public function store(Request $request, Response $response): Response
    {
        $server = $this->servers->create($this->input($request));
        $probe = $this->servers->check($server);

        return $this->json($response, ['server' => $this->servers->present($server), 'probe' => $probe], 201);
    }

    /**
     * PUT /servers/{id} — a blank secret keeps the stored one while the address stays on its host.
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $server = $this->servers->update($this->load(Server::class, $args), $this->input($request));

        return $this->json($response, ['server' => $this->servers->present($server)]);
    }

    /**
     * DELETE /servers/{id} — refused (409) while any service is on it.
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->servers->delete($this->load(Server::class, $args));

        return $this->noContent($response);
    }

    /** POST /servers/test — probe the form's input without saving anything. */
    public function test(Request $request, Response $response): Response
    {
        return $this->json($response, ['probe' => $this->servers->probeInput($this->input($request))]);
    }

    /**
     * POST /servers/{id}/test — probe a stored server and keep what it found.
     *
     * @param array<string, string> $args
     */
    public function check(Request $request, Response $response, array $args): Response
    {
        $server = $this->load(Server::class, $args);
        $probe = $this->servers->check($server);

        return $this->json($response, ['server' => $this->servers->present($server), 'probe' => $probe, 'inbounds' => $this->servers->presentInbounds($server)]);
    }

    /**
     * POST /servers/{id}/inbounds/sync — 502 when the panel cannot list them.
     *
     * @param array<string, string> $args
     */
    public function syncInbounds(Request $request, Response $response, array $args): Response
    {
        $server = $this->load(Server::class, $args);
        $this->servers->syncInbounds($server);

        return $this->detail($response, $server);
    }

    /**
     * PATCH /servers/{id}/inbounds/{inbound} — {is_selectable: bool}.
     *
     * @param array<string, string> $args
     */
    public function updateInbound(Request $request, Response $response, array $args): Response
    {
        $server = $this->load(Server::class, $args);
        $inbound = $server->inbounds()->findOrFail((int) $args['inbound']);
        \assert($inbound instanceof ServerInbound);
        $this->servers->setSelectable($inbound, $this->switch($request, 'is_selectable'));

        return $this->json($response, ['inbounds' => $this->servers->presentInbounds($server)]);
    }

    private function detail(Response $response, Server $server): Response
    {
        return $this->json($response, ['server' => $this->servers->present($server), 'inbounds' => $this->servers->presentInbounds($server)]);
    }
}
