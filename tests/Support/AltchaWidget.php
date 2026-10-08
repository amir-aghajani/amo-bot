<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A stand-in for ALTCHA's widget in a visitor's browser: it finds the number whose SHA-256, after the challenge's salt,
 * is the challenge, and hands the form its payload — the challenge, the number, the salt and the signature as base64
 * JSON, as the widget does. A fraction of a second's work, here as in a browser.
 */
final class AltchaWidget
{
    /**
     * The payload of a solved challenge (GET /captcha/challenge's answer, or Altcha::challenge()'s), `$changes` written
     * over it last — a number or a salt tampered with, a signature forged.
     *
     * @param array<string, mixed> $challenge
     * @param array<string, mixed> $changes
     */
    public static function solve(array $challenge, array $changes = []): string
    {
        $salt = (string) $challenge['salt'];
        for ($number = 0; $number <= (int) $challenge['maxnumber']; $number++) {
            if (hash('sha256', $salt . $number) === $challenge['challenge']) {
                return self::payload($changes + [
                    'algorithm' => $challenge['algorithm'],
                    'challenge' => $challenge['challenge'],
                    'number' => $number,
                    'salt' => $salt,
                    'signature' => $challenge['signature'],
                    'took' => 120,
                ]);
            }
        }

        throw new \LogicException('The challenge has no solution up to its maxnumber.');
    }

    /** @param array<string, mixed> $payload As the widget writes it: base64 of its JSON */
    public static function payload(array $payload): string
    {
        return base64_encode((string) json_encode($payload));
    }
}
