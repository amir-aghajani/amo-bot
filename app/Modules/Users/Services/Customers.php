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
 * The shop's customers as they arrive, whichever door they came through — the bot (Telegram\UserResolver) or the
 * shop's website (a sign-in with Telegram, an email proven by its code, a Google account): one row per Telegram account,
 * per email, per Google account in the shop (CurrentBot), every newcomer registered the same way. A newcomer is
 * reported to the admins' report group with whoever brought them — the customer whose referral code came with them (a
 * /start link, the website's `referral_code`) becomes their referrer as they are registered, and is told —; the agent
 * who owns a bot is its admin from their first arrival there.
 *
 * A customer's row is written only when something on it changed, or their presence (`last_seen_at`) is a minute old —
 * and a visit alone quietly (ChangeFeed::quietly()): it is no news for the panels' users area, which every visit would
 * otherwise move, making every open panel read its customer lists again.
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
     * A Telegram account's profile as the shop keeps it: the handle (without "@"), and the names — a name made only of
     * invisible format characters, as some accounts have, is no name (User::name() is null) rather than nothing shown.
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
     * The shop's customer with this Telegram account — their profile filled in as it is now (saved by seen()) — or a
     * newcomer registered with it: the one row though two of their first arrivals come at once (the second finds the
     * first's), reported, with the customer whose `$referralCode` brought them, by the arrival that made it
     * (`wasRecentlyCreated`).
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
     * A newcomer who proved their email on the shop's website — the code sent to it typed back —, with the password
     * they chose (its hash): registered as every newcomer is.
     *
     * @param array{username: string|null, first_name: string|null, last_name: string|null} $profile
     * @throws UniqueConstraintViolationException when the address has an account since — another sign-up of it, a moment ago
     */
    public function byEmail(string $email, string $passwordHash, array $profile, ?string $referralCode): User
    {
        return $this->register(static fn(): User => User::query()->create($profile + ['email' => $email, 'password_hash' => $passwordHash, 'last_seen_at' => now()]), $referralCode);
    }

    /**
     * The shop's customer with this Google account: the one it signs in already; else — the address Google speaks for
     * being theirs (`$trustedEmail`, Accounts\Services\GoogleSignIn's judgement: null for one it does not) — the account
     * with that email, which signs in with Google too from now on; else a newcomer registered with it,
     * the address theirs when Google speaks for it. Of two first sign-ins at once, one makes the row and the other finds
     * it. An account is never signed in to by its address alone when it has two-factor sign-in on (the address is no way
     * past its second step) or another Google account signs in to it: signed in its own way, the customer takes this one
     * on from their account's settings.
     *
     * @param array{username: string|null, first_name: string|null, last_name: string|null} $profile
     * @throws SignInRefusedException 409 the address is an account's that takes no Google account on by it
     */
    public function byGoogle(string $sub, ?string $trustedEmail, array $profile, ?string $referralCode): User
    {
        for ($try = 1; ; $try++) {
            $user = $this->withGoogle($sub, $trustedEmail);
            if ($user !== null) {
                return $user;
            }

            try {
                return $this->register(static fn(): User => User::query()->createOrFirst(['google_sub' => $sub], $profile + ['email' => $trustedEmail, 'last_seen_at' => now()]), $referralCode);
            } catch (UniqueConstraintViolationException $e) {
                // The address took an account in the same moment (its own sign-up): that account is the one, found again.
                if ($try === 1) {
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * The account a Google account signs in to now — by itself, or by the address Google speaks for, which takes the
     * Google account on (one conditional UPDATE while it has none) — but not one with two-factor sign-in on, nor one
     * another Google account signs in to.
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
     * The customer's row as `$make` makes it — or finds it, made by another arrival of theirs that came first — and, when
     * this call made it (`wasRecentlyCreated`), the newcomer registered with it in one transaction: whoever their code
     * says brought them, and the report group's word on it. A report the database refuses takes the newcomer back with
     * it — their next arrival registers them afresh —; the one who brought them is told once it stands.
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
        }

        return $user;
    }

    /**
     * The customer as they were just seen: whatever changed on their row is saved, and their presence with it — a visit
     * alone is written quietly, once a minute at most.
     */
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
