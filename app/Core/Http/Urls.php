<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Config\Repository as Config;

/**
 * The shop's public addresses: APP_URL (with its sub-folder) plus a path — never APP_BASE_PATH, so a sub-folder is
 * never added twice. The machine addresses are spelled here once for the routes and for Redact, which masks the
 * secret they end with.
 */
final class Urls
{
    /** The main bot's webhook. */
    public const TELEGRAM_WEBHOOK = '/webhooks/telegram/{secret}';

    /** An agent's bot's webhook: its id, then a secret of its own. */
    public const AGENT_WEBHOOK = '/webhooks/telegram/bot/{bot:[0-9]+}/{secret}';

    /** The scheduler's trigger, for a host without a real cron. */
    public const CRON = '/cron/{token}';

    /** An online payment gateway's callback: the payment's id, then a secret of its own (Payments\Services\OnlineInvoices). */
    public const PAYMENT_CALLBACK = '/payments/callback/{payment:[0-9]+}/{secret}';

    /** The website's API (the Store API): its version, then the store key that names the shop — no secret. */
    public const STORE = '/api/store/v1/{store:[0-9a-f]{24}}';

    /** @var list<string> The addresses whose last segment is a secret: a log line never holds it (Redact). */
    public const SECRET_PATHS = [self::TELEGRAM_WEBHOOK, self::AGENT_WEBHOOK, self::CRON, self::PAYMENT_CALLBACK];

    /** A route placeholder (`{secret}`, `{store:[0-9a-f]{24}}`): its name (group 1) and its pattern, if any (group 2). */
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

    /** Where an online gateway tells the shop about a payment (only ever a reason to ask the gateway itself). */
    public function paymentCallback(int $payment, string $secret): string
    {
        return $this->base() . self::fill(self::PAYMENT_CALLBACK, ['payment' => (string) $payment, 'secret' => $secret]);
    }

    /** The base address of a website's API, handed to its developer. */
    public function store(string $key): string
    {
        return $this->base() . self::fill(self::STORE, ['store' => $key]);
    }

    /** A page of a panel — the owner's (`admin`) or an agent's (`agent`): `panel('agent', '/login')`. */
    public function panel(string $panel, string $path = '/'): string
    {
        return $this->base() . '/' . $panel . $path;
    }

    /** A machine address with its placeholders as «…», so a screen never shows the secret. */
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
