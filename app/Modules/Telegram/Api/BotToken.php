<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Api;

/**
 * A bot's token as @BotFather hands it over — the bot's id, a colon, its secret — and the one way the shop learns whose
 * it is before keeping it: the main bot's from the settings screen and the installer, an agent's from the main bot.
 * One rule and one set of words for all of them.
 */
final class BotToken
{
    /** The bot's id (Telegram's numbers keep growing), a colon, the secret (35 characters today). */
    public const PATTERN = '/^\d{5,20}:[A-Za-z0-9_-]{30,64}$/';

    public const MALFORMED = 'این توکن ربات نیست؛ توکن را همان‌طور که @BotFather داده کپی کنید (مثل 123456789:AAH...).';
    public const REFUSED = 'تلگرام این توکن را نمی‌پذیرد؛ آن را دوباره از @BotFather بگیرید.';
    public const UNREACHABLE = TelegramUnreachableException::MESSAGE;

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
