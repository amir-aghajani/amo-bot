<?php

declare(strict_types=1);

namespace App\Core\Logging;

use App\Core\Http\Urls;

/**
 * Takes secrets out of text bound for a log line or an error: bot tokens (Telegram's URLs carry them), the login of an
 * address (a panel's SOCKS5 proxy: socks5h://user:password@host), a key an address carries in its query (a payment
 * gateway's: …?api_key=…) and the secret segment the machine addresses end with (Urls::SECRET_PATHS). Logs get pasted
 * into issues; a token there is the bot.
 */
final class Redact
{
    private const TOKENS = [
        // An address's login: scheme://user:password@host.
        '~\b([a-z][a-z0-9+.-]*://)[^\s/?#@\'"<>]+@~i' => '$1***@',
        // Telegram's URLs: …/bot<token>/<method> and …/file/bot<token>/<path>.
        '~bot\d+:[A-Za-z0-9_-]+~' => 'bot***',
        // A bare token (123456789:AA…, 35 characters after the colon).
        '~\b\d{5,}:[A-Za-z0-9_-]{30,}~' => '***',
        // A key in an address's query: ?api_key=…, &token=…
        '~([?&](?:api_?key|secret|token|key)=)[^&\s#\'"<>]+~i' => '$1***',
    ];

    /** One segment of a path, as far as a log line shows it. */
    private const SEGMENT = '[^/\s?#"\']';

    /** @var array<string, string>|null Pattern => replacement */
    private static ?array $patterns = null;

    public static function text(string $text): string
    {
        $patterns = self::$patterns ??= self::TOKENS + self::secretPaths();

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /**
     * A pattern per machine address matching its last segment, the secret (an address that only shares a prefix, like
     * `/webhooks/telegram/bot/…`, is not mistaken for one).
     *
     * @return array<string, string>
     */
    private static function secretPaths(): array
    {
        $patterns = [];
        foreach (Urls::SECRET_PATHS as $path) {
            $prefix = substr($path, 0, (int) strrpos($path, '{'));
            $literals = array_map(static fn(string $literal): string => preg_quote($literal, '~'), preg_split(Urls::PLACEHOLDER, $prefix) ?: []);
            $patterns['~(' . implode(self::SEGMENT . '+', $literals) . ')' . self::SEGMENT . '++(?!/)~'] = '$1***';
        }

        return $patterns;
    }
}
