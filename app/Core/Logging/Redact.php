<?php

declare(strict_types=1);

namespace App\Core\Logging;

use App\Core\Http\Urls;

/**
 * Takes the secrets out of a text bound for a log line or an error message: a bot token (a transport error carries
 * Telegram's whole URL, `…/bot<token>/getUpdates`), and the secret the shop's machine addresses end with — the
 * webhooks' and the cron token (Urls::SECRET_PATHS), which a request's path carries. A log is something an admin pastes
 * into an issue; a token there is the bot itself.
 */
final class Redact
{
    private const TOKENS = [
        // Telegram's URLs: …/bot<token>/<method> and …/file/bot<token>/<path>.
        '~bot\d+:[A-Za-z0-9_-]+~' => 'bot***',
        // A bare token (123456789:AA…, 35 characters after the colon).
        '~\b\d{5,}:[A-Za-z0-9_-]{30,}~' => '***',
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
     * Each machine address up to its secret — its own placeholders as any segment — and the secret, the last segment
     * (another address's prefix, `/webhooks/telegram/bot/…`, is not mistaken for one).
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
