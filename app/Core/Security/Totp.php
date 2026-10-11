<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * RFC 6238 one-time passwords as authenticator apps make them (SHA-1, 30-second steps, six digits): a 3x-ui panel's
 * two-factor login, and a website customer's second step. verify() takes the current step's code or one either side
 * (clock drift), and only a step later than the last one taken, so a code opens once. Uses the shop's clock (now()).
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const STEP_SECONDS = 30;
    private const DIGITS = 6;

    /** A secret's length: 160 bits, what RFC 4226 asks of one (SHA-1's own output). */
    private const SECRET_BYTES = 20;

    /** How many steps either side of now a code is still taken. */
    private const WINDOW = 1;

    /** The code for `$timestamp` (unix seconds, now by default). */
    public static function code(string $base32Secret, ?int $timestamp = null): string
    {
        return self::at(self::key($base32Secret), self::step($timestamp));
    }

    /**
     * The step (now's, or one either side) whose code `$code` is, if later than `$after` — the last step taken; null
     * otherwise. Of two matching steps the later is returned, so neither opens again.
     */
    public static function verify(string $base32Secret, string $code, ?int $after = null, ?int $timestamp = null): ?int
    {
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $key = self::key($base32Secret);
        $now = self::step($timestamp);
        for ($step = $now + self::WINDOW; $step >= $now - self::WINDOW; $step--) {
            if (($after === null || $step > $after) && hash_equals(self::at($key, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** A new secret: SECRET_BYTES random bytes, in base32 without padding (32 characters). */
    public static function secret(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(self::SECRET_BYTES)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $secret;
    }

    /**
     * The otpauth:// address (Key URI Format) an app takes the secret by, listed under the issuer and account. A colon
     * in either (the label's separator) becomes a space.
     */
    public static function uri(string $base32Secret, string $issuer, string $account): string
    {
        $issuer = trim(str_replace(':', ' ', $issuer));
        $account = trim(str_replace(':', ' ', $account));
        $query = ['secret' => $base32Secret] + ($issuer === '' ? [] : ['issuer' => $issuer]) + ['algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::STEP_SECONDS];
        $label = ($issuer === '' ? '' : rawurlencode($issuer) . ':') . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** The step a moment falls in. */
    private static function step(?int $timestamp): int
    {
        return intdiv($timestamp ?? now()->getTimestamp(), self::STEP_SECONDS);
    }

    /** The code of a step (RFC 4226's HOTP over the step's number). */
    private static function at(string $key, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** The secret's bytes; spaces, dashes, padding and lower case are accepted. */
    private static function key(string $base32Secret): string
    {
        $encoded = strtoupper(str_replace([' ', '-', '='], '', $base32Secret));
        if ($encoded === '' || preg_match('/[^A-Z2-7]/', $encoded) === 1) {
            throw new \InvalidArgumentException('The TOTP secret is not valid base32.');
        }

        $bits = '';
        foreach (str_split($encoded) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes !== '' ? $bytes : throw new \InvalidArgumentException('The TOTP secret is too short.');
    }
}
