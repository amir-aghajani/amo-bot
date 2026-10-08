<?php

declare(strict_types=1);

namespace App\Core\Drivers;

/**
 * A family's drivers, by key, in the order they are registered (bootstrap/container.php: one entry per family —
 * `database.drivers`, `mail.drivers` …). Two drivers of one key are the code's mistake.
 *
 * @template-covariant T of Driver
 */
final class Registry
{
    /** @var array<string, T> */
    private array $drivers = [];

    /**
     * @param iterable<T> $drivers
     * @throws \LogicException for two drivers of one key
     */
    public function __construct(iterable $drivers)
    {
        foreach ($drivers as $driver) {
            $key = $driver->key();
            if (isset($this->drivers[$key])) {
                throw new \LogicException("Two drivers are registered as \"{$key}\".");
            }
            $this->drivers[$key] = $driver;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    /**
     * @return T
     * @throws UnknownDriverException for a key no driver has
     */
    public function get(string $key): Driver
    {
        return $this->drivers[$key] ?? throw new UnknownDriverException(sprintf('No driver is registered as "%s" (%s).', $key, implode(', ', array_keys($this->drivers))));
    }

    /** @return T|null */
    public function find(string $key): ?Driver
    {
        return $this->drivers[$key] ?? null;
    }

    /** @return list<T> In the order they are registered */
    public function all(): array
    {
        return array_values($this->drivers);
    }
}
