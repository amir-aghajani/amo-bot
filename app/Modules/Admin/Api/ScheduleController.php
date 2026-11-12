<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Admin\Services\ScheduleScreen;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** «زمان‌بندی کارها», the owner's: how often each scheduled task runs, and the times the tasks decide by. */
final class ScheduleController extends ApiController
{
    public function __construct(private readonly ScheduleScreen $screen) {}

    /** GET /schedule */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, ['schedule' => $this->screen->present()]);
    }

    /** PUT /schedule — every field of every task, by its name */
    public function update(Request $request, Response $response): Response
    {
        $this->screen->save($this->input($request));

        return $this->show($request, $response);
    }
}
