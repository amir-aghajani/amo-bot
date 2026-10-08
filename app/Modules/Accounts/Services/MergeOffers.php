<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Modules\Accounts\DTO\MergeOffer;
use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Enums\WayIn;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A merge offered to a customer whose new way in is another account's of the shop (Identities), and taken: offer()
 * describes that account — its name, since when, its running services, its orders, its balance, its ways in — for the
 * customer to judge it is theirs, says which of the two stays (the older, AccountMerger::older()), and hands out a ticket
 * (an auth challenge of theirs, TICKET_SECONDS: the other account, the way in it holds that the customer proved theirs,
 * and what the account that stays takes once they are one). accept() spends the ticket — the asking account's alone,
 * once —, finds the other account still holding that way in, merges (AccountMerger) and gives the account that stays
 * what the offer carried: the Google account or the address being added when its slot is empty, the password chosen
 * with an email — which, set, ends every other session of the account, as any new password does. The customer is told.
 * A merge the rules refuse is said at the offer, before any ticket is made. An account reached by its address alone —
 * proven by the code emailed to it, or Google speaking for it: all a password reset asks, and a reset stops at the second
 * step — is no way past its second step either: an account with two-factor sign-in on is offered no merge that way
 * (TWO_FACTOR_ACCOUNT — signed in to it, the customer adds this account's way in from there), judged again as the ticket
 * is taken.
 */
final class MergeOffers
{
    /** How long a merge ticket waits for the customer's word. */
    public const TICKET_SECONDS = 900;

    public function __construct(
        private readonly AuthChallenges $challenges,
        private readonly AccountMerger $merger,
        private readonly CustomerSessions $sessions,
        private readonly CustomerNotifier $notifier,
    ) {}

    /**
     * Offer the customer `$user` to merge with `$other`, which holds a way in they just proved theirs (`$holds`: its
     * column and value); `$apply` is what the account that stays takes once merged; `$byAddress`, the other account was
     * reached by its email address alone (a code emailed to it, Google vouching for it).
     *
     * @param array<string, int|string> $holds
     * @param array<string, string|null> $apply
     * @throws AccountRefusedException 422 the two cannot be one; the address alone would take the customer past the other's second step
     */
    public function offer(User $user, User $other, array $holds, array $apply = [], bool $byAddress = false): MergeOffer
    {
        $refusal = self::pastSecondStep($other, $byAddress) ? AccountRefusedException::twoFactorAccount() : $this->merger->refusal($user, $other);
        if ($refusal !== null) {
            throw $refusal;
        }

        $token = $this->challenges->issue(ChallengePurpose::Merge, null, $user, ['other' => $other->id, 'holds' => $holds, 'apply' => array_filter($apply, is_string(...)), 'by_address' => $byAddress], self::TICKET_SECONDS);
        $other->loadCount(['orders', 'subscriptions as running_count' => static fn(Builder $services) => $services->where('status', SubscriptionStatus::Active->value)]);

        return new MergeOffer($token, self::TICKET_SECONDS, [
            'name' => $other->name(),
            'created_at' => $other->created_at->toIso8601String(),
            'services' => (int) $other->getAttribute('running_count'),
            'orders' => (int) $other->getAttribute('orders_count'),
            'balance' => $other->balance(),
            'telegram' => $other->telegram_id !== null,
            'email' => $other->email !== null,
            'google' => $other->google_sub !== null,
        ], AccountMerger::older($user, $other) === $user ? 'this' : 'other');
    }

    /**
     * The customer's yes to an offer, from the session `$current`: its ticket spent — theirs alone, once —, the two made
     * one, and what the offer carried given to the account that stays (a password set by it ends every other session of
     * that account; this one stays). That account, as it stands now — the request's session is its own (a merged
     * account's sessions are the survivor's) —, the customer told: the merge, and a password it set, as any is.
     *
     * @throws AccountRefusedException 422 the ticket is not theirs, spent, expired, or the other account no longer holds the way in it was about; the other turned two-factor sign-in on since its address alone reached it; or the merge the rules refuse now
     */
    public function accept(User $user, CustomerSession $current, string $token): User
    {
        $kept = $this->challenges->spend(ChallengePurpose::Merge, trim($token), $user) ?? throw AccountRefusedException::offerGone();
        $other = is_int($kept['other'] ?? null) ? User::query()->find($kept['other']) : null;
        $holds = is_array($kept['holds'] ?? null) ? $kept['holds'] : [];
        if ($other === null || !self::stillHolds($other, $holds)) {
            throw AccountRefusedException::offerGone();
        }
        if (self::pastSecondStep($other, ($kept['by_address'] ?? false) === true)) {
            throw AccountRefusedException::twoFactorAccount();
        }

        $survivor = $this->merger->merge($user, $other, AccountMerger::BY_CUSTOMER);
        $passwordSet = $this->give($survivor, is_array($kept['apply'] ?? null) ? $kept['apply'] : []);
        if ($passwordSet) {
            $this->sessions->endOthers($survivor, $current);
        }
        $this->notifier->accountMerged($survivor);
        if ($passwordSet) {
            // A new password is told as every new password is: whoever made the merge may hold one door alone.
            $this->notifier->passwordChanged($survivor);
        }

        return $survivor;
    }

    /**
     * Whether the merge would take the customer past the other account's second step: an account reached by its address
     * alone, whose password sign-in asks one.
     */
    private static function pastSecondStep(User $other, bool $byAddress): bool
    {
        return $byAddress && $other->hasTwoFactor();
    }

    /**
     * Whether the other account holds still every way in the offer was about.
     *
     * @param array<mixed> $holds
     */
    private static function stillHolds(User $other, array $holds): bool
    {
        foreach ($holds as $column => $value) {
            $held = in_array($column, WayIn::columns(), true) ? $other->getAttribute($column) : null;
            if ($held === null || !is_scalar($value) || (string) $held !== (string) $value) {
                return false;
            }
        }

        return $holds !== [];
    }

    /**
     * What the offer carried, given to the account that stays: a Google account or an address into an empty slot (one
     * no other account took meanwhile), and — the address it was chosen with being that account's — the password.
     * Whether it set the password.
     *
     * @param array<mixed> $apply
     */
    private function give(User $survivor, array $apply): bool
    {
        $fill = [];
        foreach ([WayIn::Google->column(), WayIn::Email->column()] as $column) {
            $value = $apply[$column] ?? null;
            if (is_string($value) && $survivor->getAttribute($column) === null && !User::query()->where($column, $value)->exists()) {
                $fill[$column] = $value;
            }
        }
        $password = $apply['password_hash'] ?? null;
        if (is_string($password) && ($fill['email'] ?? $survivor->email) === ($apply['email'] ?? null)) {
            $fill['password_hash'] = $password;
        }
        if ($fill === []) {
            return false;
        }

        try {
            $survivor->forceFill($fill)->save();
        } catch (UniqueConstraintViolationException) {
            // Taken by another account in the same moment: the account stays as the merge left it.
            $survivor->refresh();

            return false;
        }

        return isset($fill['password_hash']);
    }
}
