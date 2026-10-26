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
 * A merge offered when the way in a customer adds is another account's (Identities), and its acceptance. offer() refuses
 * a merge the rules refuse before any ticket exists, describes the other account for the customer to recognise, says
 * which one stays, and hands out a ticket (a challenge of theirs) that keeps what the survivor takes once they are one.
 * accept() spends the ticket once, checks the other account still holds that way in, merges, and gives the survivor
 * what the offer carried (a Google account or address for an empty slot, the password chosen with an email — which ends
 * the account's other sessions). The customer is notified.
 *
 * An account reached by its email address alone (an emailed code, or Google vouching for it) is offered no merge while
 * it has two-factor sign-in on: the address is all a password reset needs, and a reset stops at the second step, so a
 * merge must not get past it either. Judged again when the ticket is spent.
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
     * Offer `$user` a merge with `$other`, which holds a way in they just proved (`$holds`: column => value). `$apply` is
     * what the survivor takes once merged; `$byAddress` says the other account was reached by its email address alone.
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
            'created_at' => $other->created_at?->toIso8601String(),
            'services' => (int) $other->getAttribute('running_count'),
            'orders' => (int) $other->getAttribute('orders_count'),
            'balance' => $other->balance(),
            'telegram' => $other->telegram_id !== null,
            'email' => $other->email !== null,
            'google' => $other->google_sub !== null,
        ], AccountMerger::older($user, $other) === $user ? 'this' : 'other');
    }

    /**
     * The customer accepts an offer from session `$current`: the ticket is spent (theirs alone, once), the two made one,
     * and the survivor given what the offer carried. A password set this way ends the survivor's other sessions, as any
     * new password does. Answers the survivor, whose session this now is; the customer is notified of the merge and of a
     * password it set.
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
            // Notified as every new password is: whoever made the merge may hold only one of the account's channels.
            $this->notifier->passwordChanged($survivor);
        }

        return $survivor;
    }

    /** Whether the merge would get past the other account's second step: reached by its address alone, with two-factor on. */
    private static function pastSecondStep(User $other, bool $byAddress): bool
    {
        return $byAddress && $other->hasTwoFactor();
    }

    /**
     * Whether the other account still holds every way in the offer was about.
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
     * Give the survivor what the offer carried: a Google account or an address into an empty slot (unless another
     * account took it meanwhile), and the password when the address it was chosen with is the survivor's. Whether it set
     * the password.
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
