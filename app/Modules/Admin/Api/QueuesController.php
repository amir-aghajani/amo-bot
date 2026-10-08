<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Admin\Services\Queues;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /queues (both panels) — how many wait on a human in the shop (Queues): the sidebar's numbers, read again when the
 * orders, the payments or the tickets change and every minute (an order turns stuck with time alone).
 */
final class QueuesController extends ApiController
{
    public function __invoke(Request $request, Response $response): Response
    {
        return $this->json($response, ['queues' => Queues::counts()]);
    }
}
