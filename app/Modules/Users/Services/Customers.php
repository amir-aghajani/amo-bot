<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Database\ChangeFeed;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Customers as they arrive, through the bot (Telegram\UserResolver) or the website (Telegram, email or Google sign-in):
 * one row per Telegram account, email or Google account in the shop, and every newcomer registered one way — attributed
 * to whoever's referral code they came with and reported to the group, in one transaction. An agent is the admin of
 * their own bot from their first arrival. A visit alone is written at most once a minute and quietly
 * (ChangeFeed::quietly()), so it does not make every open panel reload its customer lists.
 */
final class Customers
{
    /** How old `last_seen_at` gets before a visit writes it again — the users screen says «همین حالا» within it. */
    private const SEEN_EVERY_SECONDS = 60;

    /** The longest name kept, as Telegram's are cut. */
    private const NAME_MAX = 128;

    public function __construct(
        private readonly ReferralService $referrals,
        private readonly CustomerNotifier $notifier,
        private readonly ShopReports $reports,
        private readonly ChangeFeed $changes,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * A Telegram profile as the shop keeps it: the handle without "@", and the names (one made only of invisible format
     * characters is none).
     *
     * @return array{username: string|null, first_name: string|null, last_name: string|null}
     */
    public static function profile(mixed $username, mixed $firstName, mixed $lastName): array
    {
        return [
            'username' => is_scalar($username) && (string) $username !== '' ? ltrim((string) $username, '@') : null,
            'first_name' => self::name($firstName),
            'last_name' => self::name($lastName),
        ];
    }

    /**
     * The customer with this Telegram account, profile refreshed (seen() saves it), or a newcomer registered with it —
     * one row even when two first arrivals come at once (createOrFirst).
     *
     * @param array{username: string|null, first_name: string|null, last_name: string|null} $profile
     */
    public function byTelegram(int $telegramId, array $profile, ?string $referralCode): User
    {
        $user = User::query()->where('telegram_id', $telegramId)->first();
        if ($user !== null) {
            return $user->fill($profile);
        }

        $owner = !CurrentBot::isMain() && CurrentBot::get()->agent?->telegram_id === $telegramId;
        $user = $this->register(static fn(): User => User::query()->createOrFirst(['telegram_id' => $telegramId], $profile + ['last_seen_at' => now()] + ($owner ? ['role' => UserRole::Admin] : [])), $referralCode);

        return $user->wasRecentlyCreated ? $user : $user->fill($profile);
    }

    /**
     * A newcomer who proved their email on the website, with their password's hash.
     *
     * @param array{username: string|null, first_name: string|null, last_name: string|null} $profile
     * @throws UniqueConstraintViolationException when the address has an account since — another sign-up of it, a moment ago
     */
    public function byEmail(string $email, string $passwordHash, array $profile, ?string $referralCode): User
    {
        return $this->register(static fn(): User => User::query()->create($profile + ['email' => $email, 'password_hash' => $passwordHash, 'last_seen_at' => now()]), $referralCode);
    }

    /**
     * The customer with this Google account; else the account with the address Google vouches for (`$trustedEmail`,
     * null when it does not), which takes the Google account on; else a newcomer. An account with two-factor sign-in on,
     * or linked to another Google account, is never entered by its address alone: the customer links Google from their
     * own settings.
     *
     * @param array{username: string|null, first_name: string|null, last_name: string|null} $profile
     * @throws SignInRefusedException 409 the address's account takes no Google account by it
     */
    public function byGoogle(string $sub, ?string $trustedEmail, array $profile, ?string $referralCode): User
    {
        $user = $this->withGoogle($sub, $trustedEmail);
        if ($user !== null) {
            return $user;
        }

        try {
            return $this->register(static fn(): User => User::query()->createOrFirst(['google_sub' => $sub], $profile + ['email' => $trustedEmail, 'last_seen_at' => now()]), $referralCode);
        } catch (UniqueConstraintViolationException $e) {
            // The address got an account at the same moment (its own sign-up): find that one.
            return $this->withGoogle($sub, $trustedEmail) ?? throw $e;
        }
    }

    /**
     * The account a Google account signs in to: its own, or the trusted address's, which takes it on by a conditional
     * UPDATE (of two sign-ins at once, one) — never one with two-factor sign-in on or another Google account.
     *
     * @throws SignInRefusedException 409
     */
    private function withGoogle(string $sub, ?string $trustedEmail): ?User
    {
        $user = User::query()->where('google_sub', $sub)->first();
        if ($user !== null || $trustedEmail === null) {
            return $user;
        }

        $user = User::query()->where('email', $trustedEmail)->first();
        if ($user === null) {
            return null;
        }
        if (!$user->hasTwoFactor()) {
            // Of two sign-ins at once, one takes it on.
            User::query()->whereKey($user->id)->whereNull('google_sub')->update(['google_sub' => $sub]);
            $user->refresh();
        }
        if ($user->google_sub !== $sub) {
            // Its second step, or its own Google account: signed in its own way, the customer takes this one on from
            // their account's settings.
            throw SignInRefusedException::googleAccountExists();
        }

        return $user;
    }

    /**
     * Makes the customer's row (or finds it, made by another arrival) and, when this call made it, registers the newcomer
     * in the same transaction: their referrer and the report. A failed report rolls the newcomer back (their next arrival
     * registers them afresh). After the commit the referrer is notified and the referral gifts run.
     *
     * @param \Closure(): User $make
     */
    private function register(\Closure $make, ?string $referralCode): User
    {
        [$user, $referrer] = $this->db->transaction(function () use ($make, $referralCode): array {
            $user = $make();
            if (!$user->wasRecentlyCreated) {
                return [$user, null];
            }
            $referrer = $this->referrals->attribute($user, $referralCode);
            $this->reports->newCustomer($user, $referrer);

            return [$user, $referrer];
        });
        if ($referrer !== null) {
            $this->notifier->referralJoined($referrer, $user);
            $this->referrals->joined($user, $referrer);
        }

        return $user;
    }

    /** Saves what changed on the customer's row and their presence; a visit alone quietly, once a minute at most. */
    public function seen(User $user): void
    {
        $changed = $user->isDirty();
        if ($changed || $user->last_seen_at === null || $user->last_seen_at->lt(now()->subSeconds(self::SEEN_EVERY_SECONDS))) {
            $user->last_seen_at = now();
            $changed ? $user->save() : $this->changes->quietly(static fn(): bool => $user->save());
        }
    }

    private static function name(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $visible = trim((string) preg_replace('/\p{Cf}/u', '', $value));

        return $visible === '' ? null : mb_substr(trim($value), 0, self::NAME_MAX);
    }
}
