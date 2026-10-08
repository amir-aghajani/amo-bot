<?php

declare(strict_types=1);

namespace App\Core\Config;

/**
 * The configuration by file name, read with dot notation ("session.cookie.secure"): each file in config/ returns a
 * closure that makes its part out of config.php's settings (ConfigValues). set() is for what changes while the process
 * runs — the installer's and the settings screen's new values — and the tests.
 */
final class Repository
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = []) {}

    /** @throws \LogicException when a file of `$directory` returns no closure to make its part with */
    public static function fromDirectory(string $directory, ConfigValues $settings): self
    {
        $items = [];

        foreach (glob(rtrim($directory, '/\\') . '/*.php') ?: [] as $file) {
            $part = require $file;
            if (!$part instanceof \Closure) {
                throw new \LogicException("{$file} must return a closure: static fn(ConfigValues \$settings): array => […].");
            }
            $items[basename($file, '.php')] = $part($settings);
        }

        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $target = &$this->items;

        foreach (explode('.', $key) as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }

        $target = $value;
    }
}
