<?php

declare(strict_types=1);

namespace App\Modules\Updates\Controllers;

use App\Core\Http\ApiController;
use App\Core\Http\LongRunning;
use App\Core\Session\Session;
use App\Modules\Updates\Updater;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /api/admin/system/update (the owner's panel only): the update screen, check, start, step, cancel and rollback. Each
 * answers the screen as it then stands; a refusal tells the owner the way out.
 */
final class UpdateController extends ApiController
{
    /** Seconds a request may work: the zip coming down, an install's database upgrades. */
    private const TIME_LIMIT = 300;

    public function __construct(
        private readonly Updater $updater,
        private readonly Session $session,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, ['update' => $this->updater->status()]);
    }

    public function check(Request $request, Response $response): Response
    {
        $this->settle();

        return $this->json($response, ['update' => $this->updater->check()]);
    }

    /** POST /system/update/start — {version}: the newest release, as the screen shows it */
    public function start(Request $request, Response $response): Response
    {
        $this->settle();

        return $this->json($response, ['update' => $this->updater->start(Input::text($this->input($request), 'version'))]);
    }

    public function step(Request $request, Response $response): Response
    {
        $this->settle();

        return $this->json($response, ['update' => $this->updater->step()]);
    }

    public function cancel(Request $request, Response $response): Response
    {
        $this->settle();

        return $this->json($response, ['update' => $this->updater->cancel()]);
    }

    public function rollback(Request $request, Response $response): Response
    {
        $this->settle();

        return $this->json($response, ['update' => $this->updater->rollback()]);
    }

    /**
     * Release the session (the panel's other requests need not wait on GitHub) and keep going if the owner hangs up, so
     * no step is left half-way.
     */
    private function settle(): void
    {
        $this->session->release();
        LongRunning::keepGoing(self::TIME_LIMIT);
    }
}
