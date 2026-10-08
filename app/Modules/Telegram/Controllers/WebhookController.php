<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Controllers;

use App\Core\Config\Repository as Config;
use App\Core\Http\LongRunning;
use App\Core\Http\RequestOrigin;
use App\Core\Security\RateLimiter;
use App\Core\Security\ThrottleUnavailableException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Reports\ReportSender;
use App\Modules\Telegram\Update\AgentWebhookBudget;
use App\Modules\Telegram\Update\Dispatcher;
use App\Modules\Telegram\Update\Update;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Receives the updates Telegram pushes: POST /webhooks/telegram/{secret} for the main bot, POST
 * /webhooks/telegram/bot/{bot}/{secret} for an agent's (its own secret, kept encrypted on its row). The secret is checked
 * both in the path and, when Telegram sends it, in the header, in constant time — first of all: a call that has it wrong
 * is refused alike whether or not there is such a bot, or it serves, so the answers tell nobody which bots are here. The
 * update is served in its bot's shop — once, though Telegram sends it again (the dispatcher knows it by its id) — and an
 * agent's within its bot's budget (AgentWebhookBudget: its agent can post there too); once it is answered, the reports
 * it (or anything before it) queued for that bot's report group go out. A call with a wrong secret is anyone's to make,
 * as often as they like: the log hears of REFUSALS_LOGGED of them a webhook in a window, enough to see someone at it —
 * at one bot's without hiding another's —, never a log they fill.
 */
final class WebhookController
{
    /** Seconds an update may take (each panel call is bound by its own timeout, Telegram's by OUTGOING_HTTP_TIMEOUT). */
    private const TIME_LIMIT = 180;

    /** The refused calls the log hears of in REFUSALS_WINDOW seconds, a webhook's — calls naming no agent's bot share one. */
    public const REFUSALS_LOGGED = 10;

    private const REFUSALS_WINDOW = 600;

    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly ReportSender $reports,
        private readonly AgentWebhookBudget $budget,
        private readonly RequestOrigin $origin,
        private readonly RateLimiter $counts,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array{secret: string} $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $bot = CurrentBot::main();
        if (!self::authentic($request, (string) $this->config->get('telegram.webhook_secret', ''), $args['secret'])) {
            return $this->refused($response, $request, (string) $bot->id, $bot->id);
        }

        $update = self::update($request);

        return $update === null ? $response->withStatus(400) : $this->serve($response, $bot, $update);
    }

    /** @param array{bot: string, secret: string} $args */
    public function agent(Request $request, Response $response, array $args): Response
    {
        $bot = Bot::query()->find((int) $args['bot']);
        if ($bot === null || $bot->isMain() || !self::authentic($request, (string) $bot->webhook_secret, $args['secret'])) {
            return $this->refused($response, $request, $args['bot'], $bot !== null && !$bot->isMain() ? $bot->id : null);
        }
        if (!$bot->isServing()) {
            // Its agency ended: it answers nothing — and Telegram stops retrying a 200.
            return self::ok($response);
        }

        $update = self::update($request);
        if ($update === null) {
            return $response->withStatus(400);
        }
        if (!CurrentBot::run($bot, fn(): bool => $this->budget->admits($bot, $update))) {
            return self::ok($response);
        }

        return $this->serve($response, $bot, $update);
    }

    /** The update, in its bot's shop — a slow panel may keep it past Telegram's patience —, then the bot's reports. */
    private function serve(Response $response, Bot $bot, Update $update): Response
    {
        // A slow panel can keep this answer past Telegram's patience, or the host's: what was started is finished —
        // half a purchase is worse than a late one (and the copy Telegram sends meanwhile is not served again).
        LongRunning::keepGoing(self::TIME_LIMIT);

        CurrentBot::run($bot, function () use ($update): void {
            $this->dispatcher->dispatch($update);
            $this->reports->flush();
        });

        // Always 200: Telegram retries anything else, which would replay the same update.
        return self::ok($response);
    }

    /** Whether the secret in the path — and in Telegram's header, when it sends one — is the bot's own, compared in constant time. */
    private static function authentic(Request $request, string $expected, string $secret): bool
    {
        $header = $request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token');

        return $expected !== '' && hash_equals($expected, $secret) && ($header === '' || hash_equals($expected, $header));
    }

    /** The update posted, or null for a body that is none. */
    private static function update(Request $request): ?Update
    {
        $payload = $request->getParsedBody();

        return is_array($payload) && isset($payload['update_id']) ? new Update($payload) : null;
    }

    /**
     * A call with a wrong secret — at the webhook of bot `$named` (its address's number): a 403, told to the log while the
     * webhook's window has room, and once that the rest of it goes untold. A window is a bot's (`$bot`); the calls naming
     * none of the shop's agents' bots share one, so calls at one webhook never hide those at another.
     */
    private function refused(Response $response, Request $request, string $named, ?int $bot): Response
    {
        try {
            $count = $this->counts->hit('webhook-refusals|' . ($bot ?? 'unknown'), self::REFUSALS_WINDOW);
        } catch (ThrottleUnavailableException) {
            // Uncounted, they would fill the log: the throttle's folder says so where it matters (a sign-in's 500).
            return $response->withStatus(403);
        }
        if ($count <= self::REFUSALS_LOGGED + 1) {
            $this->logger->warning($count <= self::REFUSALS_LOGGED
                ? 'Telegram webhook of bot #{bot} called with an invalid secret from {ip}'
                : 'Telegram webhook of bot #{bot} called with an invalid secret from {ip}; more such calls within {minutes} minutes go unlogged', [
                    'bot' => $named,
                    'ip' => $this->origin->clientIp($request),
                    'minutes' => intdiv(self::REFUSALS_WINDOW, 60),
                ]);
        }

        return $response->withStatus(403);
    }

    private static function ok(Response $response): Response
    {
        $response->getBody()->write('ok');

        return $response->withStatus(200);
    }
}
