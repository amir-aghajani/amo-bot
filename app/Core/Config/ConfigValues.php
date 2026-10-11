<?php

declare(strict_types=1);

namespace App\Core\Config;

use App\Support\Input;

/**
 * config.php's settings as config/*.php read them: each as its type, by the same rules the settings screen uses
 * (`'60'` is 60, `'false'` is false), and the default for one unset or unreadable. Nothing is read from the environment,
 * so no request header can spell a setting.
 */
final class ConfigValues
{
    /** @param array<string, mixed> $file What config.php sets */
    public function __construct(private readonly array $file) {}

    public function string(string $key): string
    {
        $value = $this->file[$key] ?? null;

        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : (string) ConfigKeys::default($key);
    }

    /** A whole number: 120, or '120' — Persian digits too —, as the settings screen's numbers are read. */
    public function int(string $key): int
    {
        return Input::integerOf($this->file[$key] ?? null) ?? (int) ConfigKeys::default($key);
    }

    /** A switch: true or false — or 1/0, 'true'/'false' —, as the settings screen's switches are read. */
    public function bool(string $key): bool
    {
        $value = $this->file[$key] ?? null;

        return Input::isBoolean($value) ? Input::truthy($value) : (bool) ConfigKeys::default($key);
    }

    /**
     * Every setting under `$prefix` as written, as text (a password reading "null" stays one) — a driver's own settings,
     * which its form reads with its defaults.
     *
     * @return array<string, string>
     */
    public function prefixed(string $prefix): array
    {
        $values = [];
        foreach ($this->file as $key => $value) {
            if (str_starts_with($key, $prefix) && (is_string($value) || is_int($value) || is_float($value))) {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }
}
