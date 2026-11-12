<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Admin\Services\PanelPreferences;
use App\Modules\Auth\Principal;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Both panels: what the user keeps for themselves on every device — their favorites in the settings search. */
final class PreferencesController extends ApiController
{
    public function __construct(private readonly PanelPreferences $preferences) {}

    /** GET /preferences */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, ['preferences' => $this->preferences->present(Principal::of($request))]);
    }

    /** PUT /preferences/favorites — {favorites}: the whole list, in the user's order */
    public function favorites(Request $request, Response $response): Response
    {
        return $this->json($response, ['preferences' => $this->preferences->saveFavorites(Principal::of($request), $this->input($request)['favorites'] ?? null)]);
    }
}
