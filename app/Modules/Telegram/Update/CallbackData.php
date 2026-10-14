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
     * An enum's case as an argument: its place among the enum's cases, from 1 — 0 for none. Short whatever the case's
     * value, so a button stays within the 64 bytes; a case is only ever added at the end, or an old button's place names
     * another.
     */
    public static function place(?\UnitEnum $case): int
    {
        return $case === null ? 0 : (int) array_search($case, $case::cases(), true) + 1;
    }

    /**
     * The case of `$enum` at `$place` (place()); null for 0, or a place it has no case at.
     *
     * @template T of \UnitEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    public static function caseAt(string $enum, int $place): ?\UnitEnum
    {
        return $place > 0 ? $enum::cases()[$place - 1] ?? null : null;
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
