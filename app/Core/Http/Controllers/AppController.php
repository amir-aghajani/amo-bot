<?php

declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Config\Repository as Config;
use App\Core\Http\ApiController;
use App\Core\Installation;
use App\Support\LocalTime;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/app — a panel's first question: the shop's name, whether it is installed (if not, the owner's panel opens
 * the installer) and the shop's time zone, which the panels show every date in. Public, and answered before installation.
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
