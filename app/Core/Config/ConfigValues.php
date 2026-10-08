<?php

declare(strict_types=1);

namespace App\Core\Config;

use App\Support\Input;

/**
 * config.php as config/*.php read it: each setting as its type (its ConfigKeys default says which), and the default
 * for one the file does not set — or sets to something it cannot be: written by hand, `'SESSION_LIFETIME' => 'long'`
 * reads as the default, `'SESSION_LIFETIME' => '60'` as 60 and `'APP_DEBUG' => 'false'` as false, read by the rules the
 * settings screen reads the file with (App\Support\Input), so it shows what the shop runs with. Nothing is read from the
 * environment, so no request can spell a setting (under a web server a request's headers are variables beside the
 * environment's).
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
     * Every setting under `$prefix` the file sets, as text and as it is written (a password that reads "null" stays
     * one): a database driver's DB_* settings, which it reads with defaults of its own.
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
