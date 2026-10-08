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
 * The sign-in flows' short-lived secrets, each in the current shop: issue() hands out a random secret and keeps its
 * sha256 with what the flow needs back (`payload`, encrypted), until it expires; spend() takes it back — once: spending
 * is one conditional DELETE, and of two requests spending the same secret at once only the one whose DELETE took the row
 * gets the payload. A secret is spent by whoever it was issued to: a signed-in customer's (its holder) by them alone —
 * one about a session of theirs (its subject: a redirect's state) by that session alone —, a sign-in's (issued to
 * nobody) by a sign-in alone — a redirect's state another browser brings back opens nothing of a customer whose own
 * request did not ask for it. One tried with codes counts its tries on its row (attempt():
 * CODE_ATTEMPTS at most, each counted before it is judged) — a code emailed to an address (issueCode(), checkCode(): six
 * digits, found by what it is for and the address it went to), a two-factor sign-in's second step (find(), by its
 * secret), an authenticator app's secret waiting for its first code (hold(), held(): the customer's, one at a time). What
 * expired or was never spent goes with the hourly housekeeping (prune()). What anyone may ask for — a nonce, a redirect's
 * state (ChallengePurpose::anyonesToAsk()) — a shop issues LIVE_MAX of in its lifetime at most, whoever asks from however
 * many addresses: past it, a 503 until it has room again (the log told once).
 */
final class AuthChallenges
{
    /** How long a nonce for an id_token sign-in waits for its token (POST /auth/nonce). */
    public const NONCE_SECONDS = 1800;

    /** The nonces — and, apart, the redirect states — a shop issues in their lifetime at most: what lives of them at once. */
    public const LIVE_MAX = 5000;

    /** How long an emailed code may be typed back. */
    public const CODE_SECONDS = 900;

    /**
     * How many codes a challenge takes — an emailed one's, a two-factor sign-in's, an authenticator app's first: the
     * next finds it spent, whatever is typed.
     */
    public const CODE_ATTEMPTS = 5;

    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * A new secret for `$purpose`, good for `$seconds`: 64 hex characters, of which only the sha256 is kept — one anyone
     * may ask for while the shop has room for it (room()).
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
     * Take the secret back: what was kept with it, the first time it is spent before it expires; null for a secret this
     * shop never issued for `$purpose` to `$holder` — with one, issued to that customer; without, issued to nobody (a
     * sign-in's own, which no signed-in customer's request takes, nor a sign-in theirs) — and, with `$subject`, about it
     * (a redirect's state issued to a customer's very session: no other session of theirs takes it, nor spends it) —, one
     * spent already (by this request or another at the same moment), or one that expired (gone with it).
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
     * A new code of six digits for `$purpose` about `$subject` — the address it is emailed to —, good for `$seconds` and
     * CODE_ATTEMPTS tries: one for a purpose, an address and whoever it is issued to at a time, so the one it replaces —
     * theirs, never another customer's who asked for the same address — opens nothing from now on. Six digits are no
     * secret to a hash alone, so what is kept is keyed with APP_KEY (Encrypter::mac()): the database alone does not give
     * it away.
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
     * Take a code back (attempt()): what was kept with it, when `$code` is the one issued for `$purpose` and `$subject` —
     * and, with `$holder`, issued to that customer —, before it expired and with a try left. Null otherwise: no code, a
     * wrong one, one spent, expired or tried out.
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
     * Keep `$payload` for the customer, good for `$seconds` — one for a purpose and a customer at a time: any it replaces
     * is gone (an authenticator app's secret shown again). It is found by its customer (held()), never by a secret.
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
     * One code tried on a challenge: counted first — one conditional UPDATE while it has tries left (`attempts` below
     * CODE_ATTEMPTS: two at once count twice) —, then judged by `$right` (given what was kept with it), and spent by one
     * conditional DELETE when it is right (of two right tries at once, one gets it). What was kept, for the try that
     * opened it; null for a wrong one, one past its tries, one expired, or one spent meanwhile.
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
     * One more of what anyone may ask for in the current shop, held to LIVE_MAX in a window of its lifetime — counted
     * as it is asked (RateLimiter::attempt()): a table of them grows no further, whoever asks from address after
     * address. Past it, the log is told once a window and the asker gets a 503 until the window closes.
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

    /** What is kept of a code: keyed with APP_KEY, and bound to what it is for and the address it went to. */
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
