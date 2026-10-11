<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Http\RequestOrigin;
use App\Modules\Accounts\DTO\SignedIn;
use App\Modules\Accounts\Enums\SignInMethod;
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
 * since replaces a password alone), which the admin API may ask to be strong (isStrong()).
 */
final class CustomerSessions
{
    public const IDLE_DAYS = 30;

    public const LIFETIME_DAYS = 180;

    /** The sessions a customer keeps at most, the newest. */
    public const MAX_SESSIONS = 20;

    /** How long a proven way in lets the session change how the account is signed in to. */
    public const RECENT_SECONDS = 900;

    private const TOUCH_MINUTES = 5;

    public function __construct(
        private readonly RequestOrigin $origin,
        private readonly LoggerInterface $logger,
    ) {}

    /** A new session on the device the request came from, signed in now by `$method`; their oldest ends past MAX_SESSIONS. */
    public function open(User $user, ServerRequestInterface $request, SignInMethod $method): SignedIn
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
            'authenticated_at' => now(),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);
        $session->setRelation('user', $user);

        $kept = CustomerSession::query()->where('user_id', $user->id)->orderByDesc('id')->limit(self::MAX_SESSIONS)->pluck('id')->all();
        CustomerSession::query()->where('user_id', $user->id)->whereNotIn('id', $kept)->delete();

        return new SignedIn($token, $session);
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
     * A way in proven again on the session: recent from now, and upgraded to `$method` when that is strong and its own
     * was not (a password alone proven again never downgrades it).
     */
    public function reauthenticated(CustomerSession $session, SignInMethod $method): void
    {
        $upgrade = $method->strong() && $session->method?->strong() !== true;
        $session->forceFill(['authenticated_at' => now()] + ($upgrade ? ['method' => $method] : []))->save();
    }

    /** Whether the session was signed in strongly, or a strong way was proven on it since. */
    public static function isStrong(CustomerSession $session): bool
    {
        return $session->method?->strong() === true;
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

    /** How many sessions of the customer's have not ended: the devices they are signed in on. */
    public function count(User $user): int
    {
        return self::live(CustomerSession::query())->where('user_id', $user->id)->count();
    }

    /**
     * End one of the customer's sessions by its id; another customer's is a 404.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function end(User $user, int $id): void
    {
        $this->close(CustomerSession::query()->where('user_id', $user->id)->findOrFail($id));
    }

    /**
     * The customer's sessions that have not ended, newest first.
     *
     * @return Collection<int, CustomerSession>
     */
    public function list(User $user): Collection
    {
        return self::live(CustomerSession::query())->where('user_id', $user->id)->orderByDesc('id')->get();
    }

    /**
     * A session as the customer's list shows it; `current` marks the one the request came with.
     *
     * @return array{id: int, device: string|null, ip: string|null, created_at: string, last_used_at: string|null, current: bool}
     */
    public static function present(CustomerSession $session, CustomerSession $current): array
    {
        return [
            'id' => $session->id,
            'device' => $session->device,
            'ip' => $session->ip,
            'created_at' => $session->created_at->toIso8601String(),
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

    private static function idleSince(): Carbon
    {
        return now()->subDays(self::IDLE_DAYS);
    }
}
