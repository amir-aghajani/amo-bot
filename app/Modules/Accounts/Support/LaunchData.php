<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\DTO\MiniAppLaunch;
use App\Modules\Users\Services\Customers;
use Illuminate\Support\Carbon;

/**
 * The launch data Telegram hands a mini app it opens (`Telegram.WebApp.initData`), checked as Telegram's documentation
 * says: every field but `hash`, sorted by name, as `name=value` lines, signed with HMAC-SHA256 under a key that is the
 * HMAC-SHA256 of the bot's token keyed with "WebAppData". Only Telegram, holding the token, makes such a signature; the
 * mini app's page only carries it.
 *
 * read() answers the Telegram account the data is about, or null for anything that does not hold: no signature or another
 * one, a field twice, no account, or data made longer ago than it may be used (or later than now: another clock).
 */
final class LaunchData
{
    /** The most launch data one request carries: Telegram's is well under a kilobyte. */
    public const MAX_BYTES = 4096;

    /** How far ahead of the shop's clock Telegram's may run. */
    private const CLOCK_SKEW_SECONDS = 60;

    /** @param int $maxAgeSeconds How long after Telegram made it the data still proves the account */
    public static function read(string $initData, string $botToken, int $maxAgeSeconds, ?Carbon $now = null): ?MiniAppLaunch
    {
        if ($initData === '' || $botToken === '' || strlen($initData) > self::MAX_BYTES) {
            return null;
        }

        $fields = [];
        foreach (explode('&', $initData) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if ($value === null) {
                return null;
            }
            $name = urldecode($name);
            if ($name === '' || array_key_exists($name, $fields)) {
                return null;
            }
            $fields[$name] = urldecode($value);
        }

        $hash = $fields['hash'] ?? '';
        unset($fields['hash']);
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            return null;
        }
        ksort($fields, SORT_STRING);
        $lines = [];
        foreach ($fields as $name => $value) {
            $lines[] = "{$name}={$value}";
        }
        $key = hash_hmac('sha256', $botToken, 'WebAppData', true);
        if (!hash_equals(hash_hmac('sha256', implode("\n", $lines), $key), $hash)) {
            return null;
        }

        $made = ctype_digit($fields['auth_date'] ?? '') ? (int) $fields['auth_date'] : null;
        $now = ($now ?? Carbon::now())->getTimestamp();
        if ($made === null || $made > $now + self::CLOCK_SKEW_SECONDS || $now - $made > $maxAgeSeconds) {
            return null;
        }

        $user = json_decode($fields['user'] ?? '', true);
        $id = is_array($user) ? ($user['id'] ?? null) : null;
        if (!is_int($id) || $id <= 0) {
            return null;
        }

        // Made on a clock a little ahead of the shop's, it was made now as far as the shop knows: a session proven by it is
        // never proven in the shop's future.
        return new MiniAppLaunch(
            $id,
            Customers::profile($user['username'] ?? null, $user['first_name'] ?? null, $user['last_name'] ?? null),
            Carbon::createFromTimestamp(min($made, $now)),
        );
    }
}
