<?php

declare(strict_types=1);

namespace App\Modules\Api\Controllers;

use App\Core\Config\Repository as Config;
use App\Core\Http\ApiController;
use App\Core\Installation;
use App\Support\LocalTime;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/app — what the panel asks before anything else: the shop's name, whether it is installed (when it is not,
 * the panel opens its installer), and the shop's time zone, which the panel shows every time in — as the bot words its
 * dates (LocalTime). Public, and answered on a shop not installed yet.
 */
final class AppController extends ApiController
{
    public function __construct(
        private readonly Config $config,
        private readonly Installation $installation,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'name' => (string) $this->config->get('app.name', 'AmoBot'),
            'installed' => $this->installation->isInstalled(),
            'timezone' => LocalTime::zone()->getName(),
        ]);
    }
}
