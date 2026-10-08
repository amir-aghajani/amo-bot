<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Support\Files;

/**
 * A one-time key the app writes to a file of the host's (in storage/, which the web server never serves): whoever types
 * it in proves they can read the host's files — its File Manager, FTP, a shell —, the one proof a shop on shared hosting
 * has of its owner. The web installer opens with one (InstallKey), and the panel's way back in for a lost login does
 * (RecoveryKey). Four groups of four characters without look-alikes; one with a lifetime opens nothing once it is over,
 * counted from when it was made.
 */
abstract class HostKey
{
    /** @param int|null $lifetime Seconds a key opens anything once made; null: until it is forgotten */
    public function __construct(
        private readonly string $path,
        private readonly ?int $lifetime = null,
    ) {}

    /**
     * The key — made now when there is none that still opens (of two first requests at once, one makes it and the other
     * reads it).
     *
     * @throws \RuntimeException when its folder cannot be written
     */
    public function current(): string
    {
        return $this->kept() ?? trim(Files::rewrite($this->path, fn(string $kept): string => trim($kept) !== '' && !$this->expired() ? $kept : self::generate() . "\n"));
    }

    /** Whether `$given` is the key there is now, as typed: case and the spaces around it do not matter. */
    public function matches(string $given): bool
    {
        $key = $this->kept();
        $given = strtoupper(trim($given));

        return $key !== null && $given !== '' && hash_equals($key, $given);
    }

    /** When the key there is now stops opening anything (unix seconds); null while there is none, or it never does. */
    public function expiresAt(): ?int
    {
        $made = $this->kept() !== null && $this->lifetime !== null ? Files::modifiedAt($this->path) : null;

        return $made === null ? null : $made + (int) $this->lifetime;
    }

    /** The key opens nothing any more. */
    public function forget(): void
    {
        Files::delete($this->path);
    }

    /** The key in the file while it still opens; null when there is none. */
    private function kept(): ?string
    {
        $key = trim((string) Files::read($this->path));

        return $key !== '' && !$this->expired() ? $key : null;
    }

    private function expired(): bool
    {
        if ($this->lifetime === null) {
            return false;
        }
        clearstatcache(true, $this->path);
        $made = Files::modifiedAt($this->path);

        return $made === null || $made + $this->lifetime <= time();
    }

    /**
     * Four groups of four (some 79 random bits) without look-alikes (ReadableCode): easy to copy, and to type off a phone's
     * file manager when it must be.
     */
    private static function generate(): string
    {
        $groups = [];
        for ($group = 0; $group < 4; $group++) {
            $groups[] = ReadableCode::make(4);
        }

        return implode('-', $groups);
    }
}
