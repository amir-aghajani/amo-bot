<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Database\ChangeFeed;
use App\Core\Http\ApiController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /changes (both panels): each area's version from the change feed. A panel polls it every few seconds and reloads
 * what the moved areas show.
 */
final class ChangesController extends ApiController
{
    public function __construct(private readonly ChangeFeed $changes) {}

    public function __invoke(Request $request, Response $response): Response
    {
        return $this->json($response, ['versions' => (object) $this->changes->versions()]);
    }
}
