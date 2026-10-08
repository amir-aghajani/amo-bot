<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Core\Support\Files;

/**
 * The key AmoBot's releases are signed with — its public half, resources/release-key.pub (base64), which every release
 * carries: the updater installs only what this key's owner signed, so neither GitHub nor anyone between it and the shop
 * — a hijacked account included — can hand the shop code of their own. A copy without the key (a development checkout,
 * a build made without one) is updated by hand. An Ed25519 key, checked with PHP's sodium extension; the key the next
 * update is checked with is the one the release installed now brings — how a new key reaches the shops
 * (scripts/release-key.php --rotate).
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

    /** Whether the key signed `$message`: `$signature` the detached signature in base64, as release.json.sig holds it. */
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
