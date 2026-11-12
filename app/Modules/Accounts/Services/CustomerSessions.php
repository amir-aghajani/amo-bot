<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Http\RequestOrigin;
use App\Modules\Accounts\DTO\SignedIn;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Support\DeviceName;
use App\Modules\Auth\Actor;
use App\Modules\Bots\CurrentBot;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * A customer's sign-ins on the website, one per device. open() hands the site a bearer token (64 hex characters, shown
 * once, only its sha256 kept) and find() knows a request by it, in the current shop only. A customer keeps the newest
 * MAX_SESSIONS. A session ends after IDLE_DAYS unused, LIFETIME_DAYS after the sign-in, when the customer signs out or
 * ends it, when a new password is set, or when support signs them out everywhere.
 *
 * `authenticated_at` is when a way in was last proven on the session (the sign-in, or POST /me/reauthenticate): changes
 * to how the account is signed in to ask it to be recent (isRecent()), so a leaked token alone changes nothing; the
 * website's admin API asks it over a working day (provenUntil()). `method` is how it was signed in (a strong way proven
 * since replaces a password, or a code by SMS, alone), which the admin API may ask to be strong (isStrong()) and refuses
 * when it is the mini app's launch data (isMiniApp()).
 *
 * A panel's preview of the mini app as one of the customers (openPreview(), isPreview()) is a session of theirs only in
 * what it reads: it is none of their devices — not listed, counted, ended by them, nor pushing one of theirs out.
 */
final class CustomerSessions
{
    public const IDLE_DAYS = 30;

    public const LIFETIME_DAYS = 180;

    /** The sessions a customer keeps at most, the newest. */
    public const MAX_SESSIONS = 20;

    /** How long a proven way in lets the session change how the account is signed in to. */
    public const RECENT_SECONDS = 900;

    /** How long a panel's preview of the mini app lasts from its opening, whatever its use: never extended. */
    public const PREVIEW_MINUTES = 30;

    /** What a preview session's device says it is: a panel's look, not one of the customer's own. */
    public const PREVIEW_DEVICE = 'پیش‌نمایش پنل';

    private const TOUCH_MINUTES = 5;

    public function __construct(
        private readonly RequestOrigin $origin,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * A new session on the device the request came from, signed in now by `$method` — proven at `$provenAt` when the proof
     * is older than the request (a mini app's launch data), now otherwise —; their oldest ends past MAX_SESSIONS.
     */
    public function open(User $user, ServerRequestInterface $request, SignInMethod $method, ?Carbon $provenAt = null): SignedIn
    {
        $token = bin2hex(random_bytes(32));
        $ip = $this->origin->clientIp($request);
        $session = CustomerSession::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'device' => DeviceName::of($request->getHeaderLine('User-Agent')),
            'ip' => $ip === '' ? null : mb_substr($ip, 0, 45),
            'method' => $method,
            'last_used_at' => now(),
            'authenticated_at' => $provenAt ?? now(),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);
        $session->setRelation('user', $user);

        $kept = self::own(CustomerSession::query())->where('user_id', $user->id)->orderByDesc('id')->limit(self::MAX_SESSIONS)->pluck('id')->all();
        self::own(CustomerSession::query())->where('user_id', $user->id)->whereNotIn('id', $kept)->delete();

        return new SignedIn($token, $session);
    }

    /**
     * A panel's preview of the mini app as `$user` sees it (SignInMethod::Preview): it ends PREVIEW_MINUTES from now
     * whatever its use, and proves no way in — never recent, never strong. It reads what their own session reads, through
     * the mini app's key alone, and changes nothing (Http\CustomerAuthMiddleware); it is none of their devices, and they
     * are told nothing of it — no sign-in's path is taken.
     */
    public function openPreview(User $user): SignedIn
    {
        $token = bin2hex(random_bytes(32));
        $session = CustomerSession::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'device' => self::PREVIEW_DEVICE,
            'method' => SignInMethod::Preview,
            'last_used_at' => now(),
            'authenticated_at' => null,
            'expires_at' => now()->addMinutes(self::PREVIEW_MINUTES),
        ]);
        $session->setRelation('user', $user);

        return new SignedIn($token, $session);
    }

    /** Whether the session is a panel's preview of the mini app (openPreview()). */
    public static function isPreview(CustomerSession $session): bool
    {
        return $session->method === SignInMethod::Preview;
    }

    /** Whether a way in was proven on the session within RECENT_SECONDS. */
    public static function isRecent(CustomerSession $session): bool
    {
        return self::provenUntil($session, self::RECENT_SECONDS) !== null;
    }

    /** Until when the last proof on the session counts, given it lasts `$seconds`; null once it no longer does. */
    public static function provenUntil(CustomerSession $session, int $seconds): ?Carbon
    {
        $until = $session->authenticated_at?->copy()->addSeconds($seconds);

        return $until !== null && $until->gt(now()) ? $until : null;
    }

    /**
     * A way in proven again on the session: recent from `$provenAt` (now, unless the proof was made before the request: a
     * mini app's launch data) — never less recent than it was: an older proof leaves a later one standing —, and upgraded
     * to `$method` when that is strong and its own was not (a password alone proven again never downgrades it).
     */
    public function reauthenticated(CustomerSession $session, SignInMethod $method, ?Carbon $provenAt = null): void
    {
        $provenAt ??= now();
        if ($session->authenticated_at?->gt($provenAt) === true) {
            $provenAt = $session->authenticated_at;
        }
        $upgrade = $method->strong() && $session->method?->strong() !== true;
        $session->forceFill(['authenticated_at' => $provenAt] + ($upgrade ? ['method' => $method] : []))->save();
    }

    /**
     * A new secret for the mini app to keep behind the device's fingerprint or face (Telegram's BiometricManager): only
     * its sha256 is kept, on this session alone, and proving it again (Reauthentication's `biometric`) makes the session
     * recent. A secret set before is replaced. Shown this once. Only on a session the mini app signed in (isMiniApp()):
     * a website's token, used through the mini app's public key, would keep itself recent for as long as it lasts.
     *
     * @throws AccountRefusedException 422 a session the mini app's launch data did not sign in
     */
    public function setBiometric(CustomerSession $session): string
    {
        if (!self::isMiniApp($session)) {
            throw AccountRefusedException::notMiniAppSession();
        }
        $secret = bin2hex(random_bytes(32));
        $session->forceFill(['biometric_hash' => hash('sha256', $secret)])->save();

        return $secret;
    }

    /** The session's fingerprint secret forgotten: proving it again proves nothing. */
    public function clearBiometric(CustomerSession $session): void
    {
        $session->forceFill(['biometric_hash' => null])->save();
    }

    /** Whether `$secret` is the one the session keeps behind the device's fingerprint or face. */
    public static function holdsBiometric(CustomerSession $session, string $secret): bool
    {
        return $session->biometric_hash !== null && $secret !== '' && hash_equals($session->biometric_hash, hash('sha256', $secret));
    }

    /** Whether the session was signed in strongly, or a strong way was proven on it since. */
    public static function isStrong(CustomerSession $session): bool
    {
        return $session->method?->strong() === true;
    }

    /**
     * Whether the session's way in is the mini app's launch data: signed in by it, or a password (or a code by SMS) alone
     * made strong by it. It stays so for the session's life — a strong way proven since never replaces another
     * (reauthenticated()) —,
     * which the website's admin API refuses: the mini app is the customer side alone.
     */
    public static function isMiniApp(CustomerSession $session): bool
    {
        return $session->method === SignInMethod::MiniApp;
    }

    /** The current shop's live session the token opens, with its customer; null for none. */
    public function find(string $token): ?CustomerSession
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        return self::live(CustomerSession::query()->with('user'))->where('token_hash', hash('sha256', $token))->first();
    }

    /** Record the session's use — written at most every TOUCH_MINUTES, not on every request. */
    public function touch(CustomerSession $session): void
    {
        if ($session->last_used_at === null || $session->last_used_at->lt(now()->subMinutes(self::TOUCH_MINUTES))) {
            $session->forceFill(['last_used_at' => now()])->save();
        }
    }

    /** End the session: its token opens nothing from now on. */
    public function close(CustomerSession $session): void
    {
        CustomerSession::query()->whereKey($session->id)->delete();
    }

    /** End every session of the customer's (a new password signs them out everywhere). */
    public function endAll(User $user): void
    {
        CustomerSession::query()->where('user_id', $user->id)->delete();
    }

    /** End every session of the customer's but `$keep` — the one that changed their password stays signed in. */
    public function endOthers(User $user, CustomerSession $keep): void
    {
        CustomerSession::query()->where('user_id', $user->id)->whereKeyNot($keep->id)->delete();
    }

    /** Support signs the customer out on every device (a panel, or a shop admin on the website); logged with who did. */
    public function signOutEverywhere(User $user, Actor $actor): void
    {
        $this->endAll($user);
        $this->logger->info('{reviewer} signed customer #{customer} out of the website on every device', ['reviewer' => $actor->reviewer, 'customer' => $user->id]);
    }

    /** How many sessions of the customer's have not ended: the devices they are signed in on (a panel's preview is none). */
    public function count(User $user): int
    {
        return self::live(self::own(CustomerSession::query()))->where('user_id', $user->id)->count();
    }

    /**
     * End one of the customer's sessions by its id; another customer's — or a panel's preview — is a 404.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function end(User $user, int $id): void
    {
        $this->close(self::own(CustomerSession::query())->where('user_id', $user->id)->findOrFail($id));
    }

    /**
     * The customer's sessions that have not ended, newest first; a panel's preview is none of them.
     *
     * @return Collection<int, CustomerSession>
     */
    public function list(User $user): Collection
    {
        return self::live(self::own(CustomerSession::query()))->where('user_id', $user->id)->orderByDesc('id')->get();
    }

    /**
     * A session as the customer's list shows it; `current` marks the one the request came with.
     *
     * @return array{id: int, device: string|null, ip: string|null, created_at: string|null, last_used_at: string|null, current: bool}
     */
    public static function present(CustomerSession $session, CustomerSession $current): array
    {
        return [
            'id' => $session->id,
            'device' => $session->device,
            'ip' => $session->ip,
            'created_at' => $session->created_at?->toIso8601String(),
            'last_used_at' => $session->last_used_at?->toIso8601String(),
            'current' => $session->id === $current->id,
        ];
    }

    /** Delete every shop's ended sessions (hourly housekeeping). */
    public function prune(): void
    {
        $since = self::idleSince();
        CurrentBot::everywhere(static fn() => CustomerSession::query()
            ->where(static fn(Builder $ended) => $ended
                ->where('expires_at', '<=', now())
                ->orWhere('last_used_at', '<=', $since)
                ->orWhere(static fn(Builder $never) => $never->whereNull('last_used_at')->where('created_at', '<=', $since)))
            ->delete());
    }

    /**
     * Sessions that have not ended: before their end, and used (or, never used, opened) within IDLE_DAYS.
     *
     * @param Builder<CustomerSession> $sessions
     * @return Builder<CustomerSession>
     */
    private static function live(Builder $sessions): Builder
    {
        $since = self::idleSince();

        return $sessions->where('expires_at', '>', now())->where(static fn(Builder $used) => $used
            ->where('last_used_at', '>', $since)
            ->orWhere(static fn(Builder $never) => $never->whereNull('last_used_at')->where('created_at', '>', $since)));
    }

    /**
     * The customer's own sessions: every one but a panel's preview (a session older than `method` among them).
     *
     * @param Builder<CustomerSession> $sessions
     * @return Builder<CustomerSession>
     */
    private static function own(Builder $sessions): Builder
    {
        return $sessions->where(static fn(Builder $own) => $own->whereNull('method')->orWhere('method', '!=', SignInMethod::Preview->value));
    }

    private static function idleSince(): Carbon
    {
        return now()->subDays(self::IDLE_DAYS);
    }
}
