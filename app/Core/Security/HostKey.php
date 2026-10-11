<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Support\Files;

/**
 * A one-time key written to a file under storage/ (never served): typing it in proves access to the host's files — the
 * one proof of ownership a shop on shared hosting has. The installer (InstallKey) and a lost login's recovery
 * (RecoveryKey) use one. An optional lifetime counts from when the key was made.
 */
abstract class HostKey
{
    /** @param int|null $lifetime Seconds a key opens anything once made; null: until it is forgotten */
    public function __construct(
        private readonly string $path,
        private readonly ?int $lifetime = null,
    ) {}

    /**
     * The key, made now when there is none that still opens (of two requests at once, one makes it, the other reads it).
     *
     * @throws \RuntimeException when its folder cannot be written
     */
    public function current(): string
    {
        return $this->kept() ?? trim(Files::rewrite($this->path, fn(string $kept): string => trim($kept) !== '' && !$this->expired() ? $kept : self::generate() . "\n"));
    }

    /** Whether `$given` is the current key, ignoring case and surrounding spaces. */
    public function matches(string $given): bool
    {
        $key = $this->kept();
        $given = strtoupper(trim($given));

        return $key !== null && $given !== '' && hash_equals($key, $given);
    }

    /** When the current key expires (unix seconds); null when there is none or it never does. */
    public function expiresAt(): ?int
    {
        $made = $this->kept() !== null && $this->lifetime !== null ? Files::modifiedAt($this->path) : null;

        return $made === null ? null : $made + (int) $this->lifetime;
    }

    /** Delete the key. */
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

    /** Four groups of four ReadableCode characters (~79 random bits): easy to copy, and to type off a phone. */
    private static function generate(): string
    {
        $groups = [];
        for ($group = 0; $group < 4; $group++) {
            $groups[] = ReadableCode::make(4);
        }

        return implode('-', $groups);
    }
}
