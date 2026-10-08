<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Config\Repository as Config;

/**
 * The shop's public addresses — what Telegram, a cron pinger, an agent's browser or the shop's website is handed:
 * APP_URL, the address the shop is reached at with its sub-folder (https://example.com/shop), then the path asked for.
 * Nothing else is added: the sub-folder is APP_URL's alone, so a generated address never carries it twice.
 *
 * The addresses machines call are spelled here once — routes/webhooks.php and routes/api.php route them, Redact masks
 * the secret a webhook's or the cron's ends with.
 */
final class Urls
{
    /** The main bot's webhook. */
    public const TELEGRAM_WEBHOOK = '/webhooks/telegram/{secret}';

    /** An agent's bot's webhook: its id, then a secret of its own. */
    public const AGENT_WEBHOOK = '/webhooks/telegram/bot/{bot:[0-9]+}/{secret}';

    /** The scheduler's trigger, for a host without a real cron. */
    public const CRON = '/cron/{token}';

    /** The website's API (the Store API): its version, then the store key that names the shop — no secret. */
    public const STORE = '/api/store/v1/{store:[0-9a-f]{24}}';

    /** @var list<string> The addresses whose last segment is a secret: a log line never holds it (Redact). */
    public const SECRET_PATHS = [self::TELEGRAM_WEBHOOK, self::AGENT_WEBHOOK, self::CRON];

    /**
     * A placeholder of a route's pattern as Slim reads it — `{secret}`, `{id:[0-9]+}`, `{store:[0-9a-f]{24}}` (a pattern
     * may hold a quantifier's braces) —: its name (group 1) and its pattern, when it has one (group 2).
     */
    public const PLACEHOLDER = '/\{(\w+)(?::((?:[^{}]++|\{[^{}]*\})+))?\}/';

    public function __construct(private readonly Config $config) {}

    /** APP_URL, without a trailing slash — or `$base` in its place (bot:webhook:set --url). */
    public function base(?string $base = null): string
    {
        return rtrim($base ?? (string) $this->config->get('app.url', ''), '/');
    }

    public function telegramWebhook(string $secret, ?string $base = null): string
    {
        return $this->base($base) . self::fill(self::TELEGRAM_WEBHOOK, ['secret' => $secret]);
    }

    public function agentWebhook(int $bot, string $secret, ?string $base = null): string
    {
        return $this->base($base) . self::fill(self::AGENT_WEBHOOK, ['bot' => (string) $bot, 'secret' => $secret]);
    }

    public function cron(string $token): string
    {
        return $this->base() . self::fill(self::CRON, ['token' => $token]);
    }

    /** The base address of a website's API: what its developer is handed, every endpoint under it. */
    public function store(string $key): string
    {
        return $this->base() . self::fill(self::STORE, ['store' => $key]);
    }

    /** A page of a panel — the owner's (`admin`) or an agent's (`agent`): `panel('agent', '/login')`. */
    public function panel(string $panel, string $path = '/'): string
    {
        return $this->base() . '/' . $panel . $path;
    }

    /** A machine address as a screen shows it, its placeholders «…» — the secret it ends with is never shown. */
    public function masked(string $pattern): string
    {
        return $this->base() . preg_replace(self::PLACEHOLDER, '…', $pattern);
    }

    /**
     * A route's pattern with its placeholders filled in, each value encoded as one path segment.
     *
     * @param array<string, string> $values
     */
    private static function fill(string $pattern, array $values): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            static fn(array $placeholder): string => rawurlencode($values[$placeholder[1]] ?? throw new \LogicException("No value for {{$placeholder[1]}} in {$pattern}.")),
            $pattern,
        );
    }
}
