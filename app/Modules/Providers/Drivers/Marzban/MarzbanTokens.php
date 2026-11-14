<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\Marzban;

use App\Core\Security\Encrypter;
use App\Core\Support\Files;

/**
 * The bearer tokens Marzban panels gave the shop, kept across requests — a file each under the container's
 * `panel.tokens` (storage/cache/panel-tokens) — so a panel, which reports every sign-in to its Telegram admins, is not
 * signed in to by every PHP request: the bot's steps, a sync, an import. A token is kept encrypted with APP_KEY, in a
 * file named by a keyed hash (Encrypter::mac()) of everything it was issued for — the server, the panel's address, the
 * way there and the admin's username and password (MarzbanClient) —, so a changed one never reuses it and the name
 * gives none of it away; until shortly before the end its JWT names (`exp`), else before Marzban's default day (until()).
 * An unwritable folder, an unreadable file or no APP_KEY keeps nothing, never an error: the shop then signs in as it
 * would without.
 */
final class MarzbanTokens
{
    /** The keyed hash's purpose: a key derived for file names alone. */
    private const PURPOSE = 'panel-token';

    /** Marzban's default token life (its JWT_ACCESS_TOKEN_EXPIRE_MINUTES, 1440), for a token that names no end. */
    private const DEFAULT_LIFE_SECONDS = 86_400;

    /** How long before its end a token stops being sent: the two clocks may differ a little. */
    private const MARGIN_SECONDS = 600;

    private const SUFFIX = '.token';

    public function __construct(
        private readonly Encrypter $encrypter,
        /** Where the tokens are kept (the container's `panel.tokens`). */
        private readonly string $directory,
    ) {}

    /** The token kept for `$issuedFor` while it has time left; null otherwise (one past its time is deleted). */
    public function find(string $issuedFor): ?string
    {
        try {
            $file = $this->file($issuedFor);
        } catch (\RuntimeException) {
            return null;
        }

        $kept = $this->read($file);
        if ($kept === null) {
            Files::delete($file);

            return null;
        }

        return $kept['token'];
    }

    /** Keep `$token` for `$issuedFor` — not one whose time is up already —, and delete the kept ones past their time. */
    public function keep(string $issuedFor, string $token): void
    {
        $until = self::until($token);
        if ($until <= now()->getTimestamp()) {
            return;
        }

        try {
            Files::writeAtomically($this->file($issuedFor), $this->encrypter->encrypt((string) json_encode(['token' => $token, 'until' => $until])));
        } catch (\RuntimeException) {
            return;
        }

        foreach (glob($this->directory . '/*' . self::SUFFIX) ?: [] as $file) {
            if ($this->read($file) === null) {
                Files::delete($file);
            }
        }
    }

    /**
     * Drop `$token`, which the panel refused, while it is the one kept for `$issuedFor` — not one another request kept
     * meanwhile.
     */
    public function forget(string $issuedFor, string $token): void
    {
        try {
            $file = $this->file($issuedFor);
        } catch (\RuntimeException) {
            return;
        }

        $kept = $this->read($file);
        if ($kept === null || hash_equals($kept['token'], $token)) {
            Files::delete($file);
        }
    }

    /** @throws \RuntimeException without APP_KEY */
    private function file(string $issuedFor): string
    {
        return $this->directory . '/' . $this->encrypter->mac($issuedFor, self::PURPOSE) . self::SUFFIX;
    }

    /** @return array{token: string, until: int}|null What the file keeps while its time is not up */
    private function read(string $file): ?array
    {
        $payload = Files::read($file);
        if ($payload === null) {
            return null;
        }

        try {
            $kept = json_decode($this->encrypter->decrypt($payload), true);
        } catch (\RuntimeException) {
            return null;
        }

        return is_array($kept) && is_string($kept['token'] ?? null) && is_int($kept['until'] ?? null) && $kept['until'] > now()->getTimestamp()
            ? ['token' => $kept['token'], 'until' => $kept['until']]
            : null;
    }

    /**
     * When a token stops being sent (unix seconds): a margin before the end its JWT names — MARGIN_SECONDS, or a tenth of
     * its life (from its `iat`, else from now) when that is shorter, so a panel giving tokens of a few minutes still has
     * them kept —, else MARGIN_SECONDS before Marzban's default day.
     */
    private static function until(string $token): int
    {
        $now = now()->getTimestamp();
        $parts = explode('.', $token);
        $claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true) : null;
        if (!is_array($claims) || !is_int($claims['exp'] ?? null)) {
            return $now + self::DEFAULT_LIFE_SECONDS - self::MARGIN_SECONDS;
        }

        $issued = is_int($claims['iat'] ?? null) ? $claims['iat'] : $now;

        return $claims['exp'] - min(self::MARGIN_SECONDS, intdiv(max(0, $claims['exp'] - $issued), 10));
    }
}
