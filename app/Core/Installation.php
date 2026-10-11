<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Support\Files;

/**
 * Whether the shop is installed: the installer writes storage/installed.lock when it is done. The lock also records
 * the version the database is at — set at install, moved by every upgrade (moveTo()) — where the next upgrades start.
 */
final class Installation
{
    /** The version of a database whose lock names none: installed by the first release, which did not write it. */
    public const FIRST_RELEASE = '0.1.0';

    /** The lock's one line: when the shop was installed, then the version its database is at. */
    private const LOCK = '/^(\S+)(?:\s+(\d+\.\d+\.\d+))?\s*$/';

    public function __construct(private readonly string $lockPath) {}

    public function isInstalled(): bool
    {
        return is_file($this->lockPath);
    }

    /**
     * Write the lock: when, and the version that installed the shop.
     *
     * @throws \RuntimeException when it cannot be written
     */
    public function markInstalled(): void
    {
        $this->write(date('c'), Application::VERSION);
    }

    /** The version the database is at. */
    public function version(): string
    {
        return $this->read()[2] ?? self::FIRST_RELEASE;
    }

    /**
     * Record that the database is now at `$version` (after an upgrade, an update or a rollback).
     *
     * @throws \RuntimeException when it cannot be written
     */
    public function moveTo(string $version): void
    {
        $this->write($this->read()[1] ?? date('c'), $version);
    }

    /** @return array<int, string> The lock's line, matched: [1] when the shop was installed, [2] its database's version (when it says one) */
    private function read(): array
    {
        return preg_match(self::LOCK, trim(Files::read($this->lockPath) ?? ''), $line) === 1 ? $line : [];
    }

    private function write(string $installedAt, string $version): void
    {
        Files::writeAtomically($this->lockPath, $installedAt . ' ' . $version . PHP_EOL);
    }
}
