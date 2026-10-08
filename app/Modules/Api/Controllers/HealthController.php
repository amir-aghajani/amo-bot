<?php

declare(strict_types=1);

namespace App\Modules\Api\Controllers;

use App\Core\Application;
use App\Core\Database\DatabaseManager;
use App\Core\Http\ApiController;
use App\Core\Installation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /health — liveness probe for uptime monitors. Public, so it names nothing about the server: not the PHP version
 * (the dashboard's, behind the login), and not why the database did not answer (its error names the host and the user —
 * the log has it).
 */
final class HealthController extends ApiController
{
    public function __construct(
        private readonly Installation $installation,
        private readonly DatabaseManager $database,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $database = $this->database->answers();

        return $this->json($response, [
            'status' => $database ? 'ok' : 'degraded',
            'version' => Application::VERSION,
            'installed' => $this->installation->isInstalled(),
            'database' => $database ? 'ok' : 'unavailable',
        ], $database ? 200 : 503);
    }
}
