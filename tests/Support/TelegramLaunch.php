<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Carbon;

/**
 * The launch data Telegram hands a mini app it opens (`Telegram.WebApp.initData`), made and signed here as Telegram
 * makes and signs it: its fields as a query string (each value percent-encoded, a space as `%20`), `hash` the
 * HMAC-SHA256 of every other field — `signature` among them —, sorted by name as `name=value` lines, under a key that is
 * the HMAC-SHA256 of the bot's token keyed with "WebAppData".
 */
final class TelegramLaunch
{
    /**
     * The launch data of the mini app opened for `$telegramId` — Ali Rezaei (@ali) — at `$madeAt`, from a link that
     * carried `$startParam` when one is given, signed with the bot's `$token` (the main bot's unless an agent's is
     * given: FakeTelegram::AGENT_TOKEN); `$fields` over its others (null leaves one out).
     *
     * @param array<string, string|null> $fields
     */
    public static function initData(int $telegramId, Carbon $madeAt, string $token = FakeTelegram::TOKEN, ?string $startParam = null, array $fields = []): string
    {
        return self::signed(self::fields($telegramId, $madeAt, $startParam, $fields), $token);
    }

    /**
     * The fields of such a launch, unsigned: what Telegram's clients send — the query's id, the account as JSON, the
     * chat it was opened from, when, and Telegram's own signature for third parties.
     *
     * @param array<string, string|null> $fields
     * @return array<string, string>
     */
    public static function fields(int $telegramId, Carbon $madeAt, ?string $startParam = null, array $fields = []): array
    {
        $user = ['id' => $telegramId, 'first_name' => 'Ali', 'last_name' => 'Rezaei', 'username' => 'ali', 'language_code' => 'fa', 'allows_write_to_pm' => true, 'photo_url' => 'https://t.me/i/userpic/320/ali.svg'];

        return array_filter($fields + [
            'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
            'user' => (string) json_encode($user),
            'chat_instance' => '-3788475317572404878',
            'chat_type' => 'private',
            'start_param' => $startParam,
            'auth_date' => (string) $madeAt->getTimestamp(),
            'signature' => 'SGVsbG8sIHRoaXMgaXMgVGVsZWdyYW0ncyBFZDI1NTE5IHNpZ25hdHVyZSBmb3IgdGhpcmQgcGFydGllcw-_',
        ], static fn(?string $value): bool => $value !== null);
    }

    /**
     * `$fields` with Telegram's `hash` under the bot's `$token`, as the query string the app is handed — its spaces
     * `%20`, or `+` (`$plus`) as a form encodes them.
     *
     * @param array<string, string> $fields
     */
    public static function signed(array $fields, string $token, bool $plus = false): string
    {
        return http_build_query($fields + ['hash' => self::hash($fields, $token)], '', '&', $plus ? PHP_QUERY_RFC1738 : PHP_QUERY_RFC3986);
    }

    /**
     * The hash Telegram signs `$fields` with: every field (none named `hash`), sorted by name, as `name=value` lines —
     * the values decoded —, under the bot's `$token`.
     *
     * @param array<string, string> $fields
     */
    public static function hash(array $fields, string $token): string
    {
        ksort($fields, SORT_STRING);
        $lines = array_map(static fn(string $name, string $value): string => "{$name}={$value}", array_keys($fields), $fields);

        return hash_hmac('sha256', implode("\n", $lines), hash_hmac('sha256', $token, 'WebAppData', true));
    }
}
