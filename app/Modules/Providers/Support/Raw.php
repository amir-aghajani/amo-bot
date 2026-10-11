<?php

declare(strict_types=1);

namespace App\Modules\Providers\Support;

/**
 * Defensive readers for the loosely typed JSON panels return, shared by the connectors: a number may arrive as an int, a
 * float or a string depending on the endpoint, and 3x-ui keeps some objects as JSON text inside the JSON. Each reader
 * answers its default for a missing or mistyped value instead of failing.
 */
final class Raw
{
    /** @param array<string, mixed> $raw */
    public static function int(array $raw, string $key, int $default = 0): int
    {
        $value = $raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @param array<string, mixed> $raw */
    public static function float(array $raw, string $key): float
    {
        $value = $raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * A switch sent as a boolean, a number or a word ("true", "on", "1"…).
     *
     * @param array<string, mixed> $raw
     */
    public static function bool(array $raw, string $key, bool $default = false): bool
    {
        $value = $raw[$key] ?? null;

        return match (true) {
            is_bool($value) => $value,
            is_string($value) => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            is_int($value) => $value !== 0,
            default => $default,
        };
    }

    /** @param array<string, mixed> $raw */
    public static function string(array $raw, string $key, string $default = ''): string
    {
        $value = $raw[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function array(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    public static function nullableArray(array $raw, string $key): ?array
    {
        $value = $raw[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * An object the panel may send as JSON text or as a nested object (3x-ui keeps an inbound's `settings` and
     * `streamSettings` as text).
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function json(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * The objects of a list (an endpoint's answer, or a key of one); anything else in it is dropped.
     *
     * @return list<array<string, mixed>>
     */
    public static function objectsOf(mixed $list): array
    {
        return array_values(array_filter(is_array($list) ? $list : [], is_array(...)));
    }

    /**
     * The non-empty strings of a list.
     *
     * @return list<string>
     */
    public static function stringsOf(mixed $list): array
    {
        $strings = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (is_scalar($item) && (string) $item !== '') {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    /**
     * An answer as the object it should be (empty when it is not one).
     *
     * @return array<string, mixed>
     */
    public static function objectOf(mixed $obj): array
    {
        return is_array($obj) ? $obj : [];
    }

    /** Unix milliseconds as a moment; 0 or less is none. */
    public static function millis(int $millis): ?\DateTimeImmutable
    {
        return $millis > 0 ? new \DateTimeImmutable('@' . intdiv($millis, 1000)) : null;
    }
}
