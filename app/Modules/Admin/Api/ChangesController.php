<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Database\ChangeFeed;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /changes (both panels, the website's admins): each area's version from the change feed — the owner's every
 * shop's, an agent's and a website's admins' their own shop's. A panel polls it every few seconds and reloads what the
 * moved areas show.
 */
final class ChangesController extends ApiController
{
    public function __construct(private readonly ChangeFeed $changes) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $principal = Principal::of($request);
        $versions = $this->changes->versions($principal->kind === PrincipalKind::Owner ? null : $principal->shop->id);

        return $this->json($response, ['versions' => (object) $versions]);
    }
}
