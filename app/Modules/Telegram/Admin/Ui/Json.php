<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

/**
 * The panels' JSON answers as the management screens read them (PanelBridge decodes them to arrays): a value of a row's
 * as text, a nested object, a nested list of objects, a flag — whatever else stands there read as none.
 */
final class Json
{
    /**
     * A row's value as text: a scalar, not empty; null for none.
     *
     * @param array<string, mixed> $row
     */
    public static function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * A nested object of a row's; [] for none.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function part(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * A list of a row's (its payments, the rows of a list answered), each an object: the ones that are, in order.
     *
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    public static function rows(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /**
     * Whether a row's flag (an action allowed, `held`) is on: true itself.
     *
     * @param array<string, mixed> $row
     */
    public static function on(array $row, string $key): bool
    {
        return ($row[$key] ?? false) === true;
    }
}
