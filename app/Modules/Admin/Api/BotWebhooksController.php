<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Core\Http\LongRunning;
use App\Core\Session\Session;
use App\Modules\Telegram\Services\BotWebhooks;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST|DELETE /system/webhook (the owner's panel only) — how every bot the shop runs gets its updates, for a host
 * without a shell: on webhooks at APP_URL (bot:webhook:set's work), or off them, back to polling (bot:webhook:delete's).
 * The answer says what happened to each bot; the system's mode (GET /system) follows.
 */
final class BotWebhooksController extends ApiController
{
    /** Seconds the work may take: a couple of Bot API calls a bot, each within the outgoing timeout. */
    private const TIME_LIMIT = 120;

    public function __construct(
        private readonly BotWebhooks $webhooks,
        private readonly Session $session,
    ) {}

    public function enable(Request $request, Response $response): Response
    {
        $this->settle();

        return $this->json($response, ['bots' => $this->webhooks->present($this->webhooks->enable(), BotWebhooks::ENABLED)]);
    }

    public function disable(Request $request, Response $response): Response
    {
        $this->settle();

        return $this->json($response, ['bots' => $this->webhooks->present($this->webhooks->disable(), BotWebhooks::DISABLED)]);
    }

    /**
     * Telegram is asked for each bot in turn: the session's other requests do not wait on that, and a caller who hangs
     * up leaves no bot half-way.
     */
    private function settle(): void
    {
        $this->session->release();
        LongRunning::keepGoing(self::TIME_LIMIT);
    }
}
