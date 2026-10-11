<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Update;

/**
 * The one format of a button's callback data: a route prefix ("plan:", "menu:subs") and ":"-separated arguments —
 * "plan:12:srv:3". build() writes it (refusing more than Telegram's 64 bytes), args() reads it back.
 */
final class CallbackData
{
    /** Telegram's cap on a button's callback data, in bytes. */
    public const MAX_BYTES = 64;

    private const SEPARATOR = ':';

    /** "plan:" + 12, "srv", 3 = "plan:12:srv:3"; "menu:subs" + 2 = "menu:subs:2". */
    public static function build(string $prefix, int|string ...$args): string
    {
        $data = $args === [] ? $prefix : $prefix . (str_ends_with($prefix, self::SEPARATOR) ? '' : self::SEPARATOR) . implode(self::SEPARATOR, $args);
        if (strlen($data) > self::MAX_BYTES) {
            throw new \LogicException("Callback data \"{$data}\" is longer than Telegram's " . self::MAX_BYTES . ' bytes.');
        }

        return $data;
    }

    /**
     * The arguments after `$prefix` — "plan:12:srv:3" after "plan:" is ['12', 'srv', '3'], "menu:subs" after
     * "menu:subs" is [] — or null when the data is not under that prefix ("menu:subsX" is not "menu:subs").
     *
     * @return list<string>|null
     */
    public static function args(string $data, string $prefix): ?array
    {
        if (!str_starts_with($data, $prefix)) {
            return null;
        }

        $rest = substr($data, strlen($prefix));
        if ($rest === '') {
            return [];
        }
        if (!str_ends_with($prefix, self::SEPARATOR)) {
            if (!str_starts_with($rest, self::SEPARATOR)) {
                return null;
            }
            $rest = substr($rest, strlen(self::SEPARATOR));
        }

        return explode(self::SEPARATOR, $rest);
    }
}
