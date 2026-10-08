<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui\Support;

/**
 * Defensive readers for the loosely typed JSON the panel returns: numbers arrive as ints, floats or strings depending
 * on the endpoint, and an inbound's settings come as JSON text inside the JSON.
 *
 * @internal the 3x-ui DTOs' and API's
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

    /** @param array<string, mixed> $raw */
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
     * An object the panel may send as JSON text — an inbound's `settings` and `streamSettings` are kept as text on its
     * side — or as a nested object.
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
     * The objects of a list (an endpoint's `obj`, or a key of one).
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
     * An endpoint's `obj` as the object it should be (empty when it is not one).
     *
     * @return array<string, mixed>
     */
    public static function objectOf(mixed $obj): array
    {
        return is_array($obj) ? $obj : [];
    }

    /** Unix milliseconds as a moment; 0 (or none) is no moment at all. */
    public static function millis(int $millis): ?\DateTimeImmutable
    {
        return $millis > 0 ? new \DateTimeImmutable('@' . intdiv($millis, 1000)) : null;
    }
}
