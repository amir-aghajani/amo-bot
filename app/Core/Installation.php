<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Support\Files;

/**
 * Whether this copy of the shop has been set up: the web installer writes the lock file when it is done. Until then only
 * the installer answers, afterwards it is gone (Http\Middleware\InstalledMiddleware). The lock also says which version
 * the shop's database is at — the version that installed it, moved by every upgrade and update since (moveTo()) —, where
 * the next upgrades start from (Database\Upgrades). The path is the container's `installation.lock`, so the tests play
 * an installed or a fresh shop without touching this machine's.
 */
final class Installation
{
    /**
     * The version of a database whose lock names none — one installed before the lock said it: the first release's,
     * where the upgrades start (there were none before it).
     */
    public const FIRST_RELEASE = '0.1.0';

    /** The lock's one line: when the shop was installed, then the version its database is at. */
    private const LOCK = '/^(\S+)(?:\s+(\d+\.\d+\.\d+))?\s*$/';

    public function __construct(private readonly string $lockPath) {}

    public function isInstalled(): bool
    {
        return is_file($this->lockPath);
    }

    /**
     * The lock says when, and which version installed the shop.
     *
     * @throws \RuntimeException when it cannot be written
     */
    public function markInstalled(): void
    {
        $this->write(date('c'), Application::VERSION);
    }

    /** The version the shop's database is at: the one that installed it, or the last that moved it. */
    public function version(): string
    {
        return $this->read()[2] ?? self::FIRST_RELEASE;
    }

    /**
     * The shop's database is at `$version` now: an upgrade brought it there, or an update installed its release — or
     * took it back. When the shop was installed stays as the lock says it.
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
