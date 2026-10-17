<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Api;

/**
 * A bot token as @BotFather hands it over (the bot's id, a colon, the secret), and the one check every place that
 * accepts one runs before keeping it (the settings screen, the installer, an agent in the main bot).
 */
final class BotToken
{
    /** The bot's id (Telegram's numbers keep growing), a colon, the secret (35 characters today). */
    public const PATTERN = '/^\d{5,20}:[A-Za-z0-9_-]{30,64}$/';

    // A left-to-right mark (U+200E) before the handle and the example: in a Persian line, in Telegram as in the panel,
    // «@BotFather» would read «BotFather@» and the token its two halves swapped.
    public const MALFORMED = "این توکن ربات نیست؛ توکن را همان‌طور که \u{200E}@BotFather داده کپی کنید (مثل \u{200E}123456789:AAH...).";
    public const REFUSED = "تلگرام این توکن را نمی‌پذیرد؛ آن را دوباره از \u{200E}@BotFather بگیرید.";
    public const UNREACHABLE = TelegramUnreachableException::MESSAGE;

    /** Whether the text has a token's shape (asking Telegram is identify()'s). */
    public static function isWellFormed(string $token): bool
    {
        return preg_match(self::PATTERN, $token) === 1;
    }

    /** The bot's own Telegram user id: the number in front of the colon; null for what is no token. */
    public static function botId(string $token): ?int
    {
        $id = strstr($token, ':', true);

        return is_string($id) && $id !== '' && ctype_digit($id) ? (int) $id : null;
    }

    /**
     * Who the token's bot is, as Telegram says (getMe): its id, @username (without the "@") and the name it shows.
     *
     * @return array{id: int, username: string, name: string}
     * @throws BotTokenException MALFORMED, REFUSED (Telegram knows no bot by it) or UNREACHABLE (Telegram could not be asked)
     */
    public static function identify(BotApi $api, string $token): array
    {
        $token = trim($token);
        if (!self::isWellFormed($token)) {
            throw new BotTokenException(self::MALFORMED);
        }

        try {
            return $api->forToken($token)->identity();
        } catch (TelegramApiException $e) {
            throw new BotTokenException($e->is(Refusal::TokenRejected) ? self::REFUSED : self::UNREACHABLE, $e);
        }
    }
}
