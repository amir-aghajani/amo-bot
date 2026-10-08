<?php

declare(strict_types=1);

namespace Tests\Support;

use Firebase\JWT\JWT;

/**
 * The RSA key the tests' OpenID providers sign their id_tokens with (FakeTelegramLogin, FakeGoogleLogin) — made once a
 * process: its private half to sign with, its public one as the JWK a provider publishes under a key id of its own.
 */
final class SigningKey
{
    /** @var array{private: string, n: string, e: string}|null */
    private static ?array $key = null;

    public static function private(): string
    {
        return self::key()['private'];
    }

    /** @return array<string, string> The public half as a provider publishes it in its JWK set, under `$kid`. */
    public static function jwk(string $kid): array
    {
        $key = self::key();

        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => $key['n'], 'e' => $key['e']];
    }

    /**
     * The process's RSA key. A Windows PHP without an openssl.cnf of its own makes no key until it is handed one — an
     * empty file is enough.
     *
     * @return array{private: string, n: string, e: string}
     */
    private static function key(): array
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $config = null;
        $key = openssl_pkey_new($options);
        if ($key === false) {
            // The first try's failure is forgotten: nothing else is to read it off OpenSSL's queue.
            do {
                $error = openssl_error_string();
            } while ($error !== false);
            $config = tempnam(sys_get_temp_dir(), 'amobot-openssl-') ?: throw new \RuntimeException('No file for an empty openssl.cnf.');
            $options['config'] = $config;
            $key = openssl_pkey_new($options) ?: throw new \RuntimeException('OpenSSL made no RSA key for the tests.');
        }
        try {
            openssl_pkey_export($key, $private, null, $options) ?: throw new \RuntimeException('OpenSSL exported no RSA key for the tests.');
        } finally {
            if ($config !== null) {
                unlink($config);
            }
        }
        $rsa = (openssl_pkey_get_details($key) ?: [])['rsa'] ?? throw new \RuntimeException('OpenSSL described no RSA key.');

        return self::$key = ['private' => (string) $private, 'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e'])];
    }
}
