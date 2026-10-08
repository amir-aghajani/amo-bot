<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Database\PageRequest;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Telegram\Broadcasts\BroadcastControl;
use App\Modules\Telegram\Broadcasts\BroadcastDirectory;
use App\Modules\Telegram\Models\Broadcast;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «پیام همگانی» on the broadcasts page: every run, newest first, live through the change feed; pause, resume and cancel
 * one under way, and take a finished pinned one's pins off («لغو پین»). A refused state is a 422 on `status`. Sending
 * is the bot's (/broadcast): the message is the admin's own Telegram message.
 */
final class BroadcastsController extends ApiController
{
    public function __construct(private readonly BroadcastDirectory $broadcasts) {}

    /** GET /broadcasts?page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->broadcasts->page(PageRequest::fromQuery($request->getQueryParams()))->toArray('broadcasts'));
    }

    /**
     * POST /broadcasts/{id}/pause
     *
     * @param array<string, string> $args
     */
    public function pause(Request $request, Response $response, array $args): Response
    {
        return $this->control($response, $args, BroadcastControl::Pause);
    }

    /**
     * POST /broadcasts/{id}/resume — the scheduler carries on with it
     *
     * @param array<string, string> $args
     */
    public function resume(Request $request, Response $response, array $args): Response
    {
        return $this->control($response, $args, BroadcastControl::Resume);
    }

    /**
     * POST /broadcasts/{id}/cancel — those who got it keep it
     *
     * @param array<string, string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        return $this->control($response, $args, BroadcastControl::Cancel);
    }

    /**
     * POST /broadcasts/{id}/unpin — answers with the new «لغو پین» run
     *
     * @param array<string, string> $args
     */
    public function unpin(Request $request, Response $response, array $args): Response
    {
        $run = $this->broadcasts->unpin($this->load(Broadcast::class, $args), Principal::of($request)->actor());

        return $this->json($response, ['broadcast' => $this->broadcasts->present($run)], 201);
    }

    /** @param array<string, string> $args */
    private function control(Response $response, array $args, BroadcastControl $control): Response
    {
        $broadcast = $this->load(Broadcast::class, $args);
        $this->broadcasts->control($broadcast, $control);

        return $this->json($response, ['broadcast' => $this->broadcasts->present($broadcast)]);
    }
}
