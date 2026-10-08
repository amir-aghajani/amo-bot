<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Admin\Services\DashboardStats;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /dashboard?range=7|30|90 (both panels) — the period's figures against the one before it; an
 * unknown range falls back to the default one.
 */
final class DashboardController extends ApiController
{
    public function __construct(private readonly DashboardStats $stats) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $days = Input::integer($request->getQueryParams(), 'range');
        if (!in_array($days, DashboardStats::RANGES, true)) {
            $days = DashboardStats::DEFAULT_RANGE;
        }

        return $this->json($response, $this->stats->summary($days));
    }
}
