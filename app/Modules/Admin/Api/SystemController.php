<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Admin\Services\SystemStatus;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /system (the owner's panel only) — whether the machinery behind the shop is alive: the dashboard's system card. */
final class SystemController extends ApiController
{
    public function __construct(private readonly SystemStatus $system) {}

    public function __invoke(Request $request, Response $response): Response
    {
        return $this->json($response, ['system' => $this->system->present()]);
    }
}
