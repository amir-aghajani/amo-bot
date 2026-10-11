<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Core\Support\Files;

/**
 * The Ed25519 public key releases are signed with (resources/release-key.pub, base64), checked with sodium. Only what
 * it signed is installed, so neither GitHub nor anyone in between — a hijacked account included — can ship code to the
 * shops. Each release carries the key the next one is checked with, which is how a new key reaches the shops.
 */
final class ReleaseKey
{
    /** An Ed25519 public key's length, and a signature's. */
    private const KEY_BYTES = 32;
    private const SIGNATURE_BYTES = 64;

    public function __construct(private readonly string $path) {}

    /** Whether there is a key to trust: the file there, holding one. */
    public function exists(): bool
    {
        return $this->key() !== null;
    }

    /** Whether the key signed `$message` (`$signature`: the detached signature, base64). */
    public function signed(string $message, string $signature): bool
    {
        $key = $this->key();
        $raw = base64_decode(trim($signature), true);
        if ($key === null || !is_string($raw) || strlen($raw) !== self::SIGNATURE_BYTES || !function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($raw, $message, $key);
    }

    /** The key's bytes; null when the file is missing or empty, or holds no key. */
    private function key(): ?string
    {
        $key = base64_decode(trim(Files::read($this->path) ?? ''), true);

        return is_string($key) && strlen($key) === self::KEY_BYTES ? $key : null;
    }
}
