<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * AES-256-GCM authenticated encryption keyed by APP_KEY ("base64:<32 random bytes>"); a payload is
 * base64(iv[12] . tag[16] . ciphertext). Keyed hashes (mac()) use keys derived from APP_KEY with HKDF, one per purpose,
 * so one key never serves two algorithms or two uses.
 */
final class Encrypter
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const KEY_PREFIX = 'base64:';

    /** The default MAC key's HKDF label. */
    private const MAC_LABEL = 'amobot-mac';

    private readonly string $macKey;

    public function __construct(private readonly string $key)
    {
        $this->macKey = $key === '' ? '' : hash_hkdf('sha256', $key, 32, self::MAC_LABEL);
    }

    public static function fromKey(string $encoded): self
    {
        if ($encoded === '') {
            return new self('');
        }

        if (str_starts_with($encoded, self::KEY_PREFIX)) {
            $encoded = substr($encoded, strlen(self::KEY_PREFIX));
        }

        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) !== 32) {
            throw new \InvalidArgumentException('APP_KEY in config.php must be a base64 encoded 32-byte key (the web installer writes one).');
        }

        return new self($raw);
    }

    public static function generateKey(): string
    {
        return self::KEY_PREFIX . base64_encode(random_bytes(32));
    }

    public function isReady(): bool
    {
        return $this->key !== '';
    }

    public function encrypt(string $plaintext): string
    {
        $this->assertReady();

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . (openssl_error_string() ?: 'unknown error'));
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * HMAC-SHA256 under a key derived for `$purpose`: for what is too short to keep as a bare hash (an emailed six-digit
     * code, a recovery code) — the database alone does not give it away — and for signing (ALTCHA's challenges).
     */
    public function mac(string $text, string $purpose = self::MAC_LABEL): string
    {
        $this->assertReady();

        return hash_hmac('sha256', $text, $purpose === self::MAC_LABEL ? $this->macKey : hash_hkdf('sha256', $this->key, 32, $purpose));
    }

    public function decrypt(string $payload): string
    {
        $this->assertReady();

        $raw = base64_decode($payload, true);
        $minimum = self::IV_LENGTH + self::TAG_LENGTH;

        if ($raw === false || strlen($raw) < $minimum) {
            throw new DecryptionException('Encrypted payload is malformed.');
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, $minimum);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            throw new DecryptionException('Could not decrypt payload (wrong APP_KEY or tampered data).');
        }

        return $plaintext;
    }

    private function assertReady(): void
    {
        if (!$this->isReady()) {
            throw new \RuntimeException('APP_KEY is not set in config.php (the web installer writes one).');
        }
    }
}
