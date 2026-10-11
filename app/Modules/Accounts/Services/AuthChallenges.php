<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Security\Encrypter;
use App\Core\Security\RateLimiter;
use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Exceptions\SignInsBusyException;
use App\Modules\Accounts\Models\AuthChallenge;
use App\Modules\Bots\CurrentBot;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;

/**
 * The sign-in flows' short-lived secrets (`auth_challenges`, per shop). issue() hands out a random secret and keeps only
 * its sha256 with an encrypted payload until it expires; spend() takes it back once — one conditional DELETE, so of two
 * requests spending one secret at once only one gets the payload.
 *
 * A secret is spent only by whoever it was issued to: a signed-in customer's (its holder, and with a subject that very
 * session) by them alone, a sign-in's (issued to nobody) by a sign-in alone. So a redirect state another browser brings
 * back opens nothing of a customer who did not ask for it.
 *
 * Challenges tried with codes count their tries on the row (attempt(), CODE_ATTEMPTS, each counted before it is judged):
 * emailed codes (issueCode()/checkCode()), a two-factor sign-in's step (find()), an app secret waiting for its first code
 * (hold()/held()). What anyone may ask for without an account (a nonce, a redirect state) is capped at LIVE_MAX per shop
 * in its lifetime, so a stranger asking from address after address cannot fill the table: past it, a 503.
 */
final class AuthChallenges
{
    /** How long a nonce for an id_token sign-in waits for its token (POST /auth/nonce). */
    public const NONCE_SECONDS = 1800;

    /** The nonces — and separately the redirect states — a shop issues within their lifetime at most. */
    public const LIVE_MAX = 5000;

    /** How long an emailed code may be typed back. */
    public const CODE_SECONDS = 900;

    /** How many codes a challenge takes; the next finds it spent, whatever is typed. */
    public const CODE_ATTEMPTS = 5;

    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * A new secret for `$purpose`, good for `$seconds`: 64 hex characters, only its sha256 kept. One anyone may ask for
     * is held to the shop's room (room()).
     *
     * @param array<string, mixed> $payload What spend() gives back
     * @throws SignInsBusyException 503: the shop issued LIVE_MAX of them in their lifetime
     */
    public function issue(ChallengePurpose $purpose, ?string $subject, ?User $user, array $payload, int $seconds): string
    {
        if ($purpose->anyonesToAsk()) {
            $this->room($purpose, $seconds);
        }
        $secret = bin2hex(random_bytes(32));
        $this->keep($purpose, $subject, $user, hash('sha256', $secret), $payload, $seconds);

        return $secret;
    }

    /** A nonce for an id_token sign-in: the site hands it to the provider, the token comes back with it, the sign-in spends it. */
    public function nonce(): string
    {
        return $this->issue(ChallengePurpose::Nonce, null, null, [], self::NONCE_SECONDS);
    }

    /**
     * Take the secret back: its payload the first time, before it expires. Null for a secret not issued for `$purpose` to
     * `$holder` (none: issued to nobody) and, with `$subject`, about it; for one already spent (now or by a request at the
     * same moment); and for one expired, which goes all the same.
     *
     * @return array<string, mixed>|null
     */
    public function spend(ChallengePurpose $purpose, string $secret, ?User $holder = null, ?string $subject = null): ?array
    {
        $challenges = self::bySecret($purpose, $secret);
        if ($subject !== null) {
            $challenges->where('subject', $subject);
        }
        $challenge = self::isSecret($secret) ? self::heldBy($challenges, $holder)->first() : null;
        if ($challenge === null || AuthChallenge::query()->whereKey($challenge->id)->delete() !== 1 || $challenge->expires_at->isPast()) {
            return null;
        }

        return self::payloadOf($challenge);
    }

    /** The challenge a secret names while codes may be tried on it (attempt()): not expired, its tries not used up. */
    public function find(ChallengePurpose $purpose, string $secret): ?AuthChallenge
    {
        return self::isSecret($secret) ? self::live(self::bySecret($purpose, $secret)->first()) : null;
    }

    /**
     * A new six-digit code for `$purpose` about `$subject` (the address it is emailed to), good for `$seconds` and
     * CODE_ATTEMPTS tries. It replaces the same holder's last code for that address — never another customer's. Six digits
     * are no secret to a bare hash, so it is kept as a hash keyed with APP_KEY: the database alone does not give it away.
     *
     * @param array<string, mixed> $payload What checkCode() gives back
     */
    public function issueCode(ChallengePurpose $purpose, string $subject, ?User $user, array $payload, int $seconds): string
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        self::heldBy(AuthChallenge::query()->where('purpose', $purpose->value)->where('subject', $subject), $user)->delete();
        $this->keep($purpose, $subject, $user, $this->codeHash($purpose, $subject, $code), $payload, $seconds);

        return $code;
    }

    /**
     * Try a code (attempt()): its payload when `$code` is the latest one issued for `$purpose` and `$subject` (with
     * `$holder`, to that customer), unexpired and with a try left. Null for anything else.
     *
     * @return array<string, mixed>|null
     */
    public function checkCode(ChallengePurpose $purpose, string $subject, string $code, ?User $holder = null): ?array
    {
        $codes = AuthChallenge::query()->where('purpose', $purpose->value)->where('subject', $subject);
        if ($holder !== null) {
            $codes->where('user_id', $holder->id);
        }
        $challenge = $codes->latest('id')->first();
        $hash = $this->codeHash($purpose, $subject, $code);

        return $challenge === null ? null : $this->attempt($challenge, static fn(): bool => hash_equals($challenge->secret_hash, $hash));
    }

    /**
     * Keep `$payload` for the customer for `$seconds`, replacing their last one for `$purpose`. It is found by its
     * customer (held()), never by a secret.
     *
     * @param array<string, mixed> $payload
     */
    public function hold(ChallengePurpose $purpose, User $user, array $payload, int $seconds): void
    {
        AuthChallenge::query()->where('purpose', $purpose->value)->where('user_id', $user->id)->delete();
        $this->keep($purpose, null, $user, hash('sha256', random_bytes(32)), $payload, $seconds);
    }

    /** What hold() kept for the customer, while codes may still be tried on it (attempt()). */
    public function held(ChallengePurpose $purpose, User $user): ?AuthChallenge
    {
        return self::live(AuthChallenge::query()->where('purpose', $purpose->value)->where('user_id', $user->id)->latest('id')->first());
    }

    /**
     * One code tried on a challenge: counted first by a conditional UPDATE while tries are left (two at once count twice),
     * judged by `$right`, then spent by a conditional DELETE (of two right tries at once, one wins). The payload for the
     * try that opened it; null otherwise.
     *
     * @param \Closure(array<string, mixed>): bool $right
     * @return array<string, mixed>|null
     */
    public function attempt(AuthChallenge $challenge, \Closure $right): ?array
    {
        if ($challenge->expires_at->isPast()) {
            return null;
        }

        $tried = AuthChallenge::query()->whereKey($challenge->id)->where('attempts', '<', self::CODE_ATTEMPTS)->increment('attempts');
        $payload = self::payloadOf($challenge);
        if ($tried !== 1 || !$right($payload)) {
            return null;
        }

        return AuthChallenge::query()->whereKey($challenge->id)->delete() === 1 ? $payload : null;
    }

    /** Forget every shop's challenges that expired: nobody can spend them any more. */
    public function prune(): void
    {
        CurrentBot::everywhere(static fn() => AuthChallenge::query()->where('expires_at', '<=', now())->delete());
    }

    /**
     * Count one more of what anyone may ask for in the current shop, at most LIVE_MAX within its lifetime. Past it a
     * warning is logged once a window and the asker gets a 503 until the window closes.
     *
     * @throws SignInsBusyException
     */
    private function room(ChallengePurpose $purpose, int $seconds): void
    {
        $key = 'challenges|' . CurrentBot::id() . '|' . $purpose->value;
        $wait = $this->limiter->attempt([[$key, self::LIVE_MAX, $seconds]]);
        if ($wait === 0) {
            return;
        }
        if ($this->limiter->hit("{$key}|logged", $seconds) === 1) {
            $this->logger->warning('The website of shop {shop} issued {max} of {purpose} in {seconds} seconds — no more for {wait} seconds: someone asks for them by the thousand', [
                'shop' => CurrentBot::id(),
                'max' => self::LIVE_MAX,
                'purpose' => $purpose->value,
                'seconds' => $seconds,
                'wait' => $wait,
            ]);
        }

        throw new SignInsBusyException();
    }

    /** @param array<string, mixed> $payload */
    private function keep(ChallengePurpose $purpose, ?string $subject, ?User $user, string $hash, array $payload, int $seconds): void
    {
        AuthChallenge::query()->create([
            'purpose' => $purpose,
            'subject' => $subject,
            'user_id' => $user?->id,
            'secret_hash' => $hash,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'expires_at' => now()->addSeconds($seconds),
        ]);
    }

    /** A code's keyed hash, bound to its purpose and the address it went to. */
    private function codeHash(ChallengePurpose $purpose, string $subject, string $code): string
    {
        return $this->encrypter->mac("{$purpose->value}|{$subject}|{$code}");
    }

    /** @return Builder<AuthChallenge> The current shop's challenge for `$purpose` a secret names. */
    private static function bySecret(ChallengePurpose $purpose, string $secret): Builder
    {
        return AuthChallenge::query()->where('purpose', $purpose->value)->where('secret_hash', hash('sha256', $secret));
    }

    /**
     * @param Builder<AuthChallenge> $challenges
     * @return Builder<AuthChallenge> Those issued to `$holder` — to nobody, without one
     */
    private static function heldBy(Builder $challenges, ?User $holder): Builder
    {
        return $holder === null ? $challenges->whereNull('user_id') : $challenges->where('user_id', $holder->id);
    }

    /** The challenge while codes may be tried on it: not expired, its tries not used up. */
    private static function live(?AuthChallenge $challenge): ?AuthChallenge
    {
        return $challenge !== null && !$challenge->expires_at->isPast() && $challenge->attempts < self::CODE_ATTEMPTS ? $challenge : null;
    }

    /** A secret as issue() hands them out: 64 hex characters — anything else names none. */
    private static function isSecret(string $secret): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $secret) === 1;
    }

    /** @return array<string, mixed> */
    private static function payloadOf(AuthChallenge $challenge): array
    {
        $payload = json_decode((string) $challenge->payload, true);

        return is_array($payload) ? $payload : [];
    }
}
