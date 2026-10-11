<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Admin\Services\Queues;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /queues (both panels): how much waits on a human in the shop (Queues) — the sidebar's badges. */
final class QueuesController extends ApiController
{
    public function __invoke(Request $request, Response $response): Response
    {
        return $this->json($response, ['queues' => Queues::counts()]);
    }
}
