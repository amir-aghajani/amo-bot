<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Agency\Services\AgencySettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The agency program's rules («تنظیمات», a section of the owner's agents page): whether it takes requests, the credit an
 * approved agent starts with, the traffic agents buy. The shop's own, not a bot's.
 */
final class AgencySettingsController extends ApiController
{
    public function __construct(private readonly AgencySettings $settings) {}

    /** GET /agency/settings */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, ['settings' => $this->settings->present()]);
    }

    /** PUT /agency/settings — {enabled, default_credit, traffic_presets, traffic_min} */
    public function update(Request $request, Response $response): Response
    {
        $this->settings->save($this->input($request));

        return $this->show($request, $response);
    }
}
