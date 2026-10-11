<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Accounts\Models\AccountMerge;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Agency\Services\AgencyDirectory;
use App\Modules\Discounts\Services\Loyalty;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;

/**
 * A customer's page in either panel (`GET /users/{id}`): their row as the users table has it, their referral facts, their
 * agency (an agent of the main bot's), their website account (account()) and their loyalty standing. Their services,
 * orders and payments are those lists' own, narrowed with `user=`.
 */
final class CustomerProfile
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly ReferralService $referrals,
        private readonly AgencyDirectory $agency,
        private readonly CustomerSessions $sessions,
        private readonly Loyalty $loyalty,
    ) {}

    /** @return array{user: array<string, mixed>, referral: array{referrer: array<string, mixed>|null, referrals: int, earned: string}, agency: array<string, mixed>|null, account: array<string, mixed>, loyalty: array<string, mixed>} */
    public function present(User $user): array
    {
        $referrer = $user->referrer;

        return [
            'user' => $this->users->presentWithCounts($user),
            'referral' => ['referrer' => $referrer === null ? null : UserDirectory::presentRef($referrer)] + $this->referrals->statsFor($user),
            // Agents are the main bot's customers: in an agent's shop nobody is one.
            'agency' => $user->isAgent() ? $this->agency->presentAgent($this->agency->agent($user->id)) : null,
            'account' => $this->account($user),
            'loyalty' => $this->loyalty->standing($user),
        ];
    }

    /**
     * The customer's website account as support reads it: its ways in, two-factor sign-in, the devices signed in, and the
     * accounts merged into it, newest first.
     *
     * @return array{email: string|null, google: bool, has_password: bool, two_factor: bool, sessions: int, merges: list<array{merged_user_id: int, merged: array{telegram_id: int|null, username: string|null, email: string|null, google: bool, name: string|null}, created_at: string}>}
     */
    public function account(User $user): array
    {
        $merges = AccountMerge::query()->where('user_id', $user->id)->orderByDesc('id')->get()->map(static fn(AccountMerge $merge): array => [
            'merged_user_id' => $merge->merged_user_id,
            'merged' => [
                'telegram_id' => $merge->merged['telegram_id'] ?? null,
                'username' => $merge->merged['username'] ?? null,
                'email' => $merge->merged['email'] ?? null,
                'google' => (bool) ($merge->merged['google'] ?? false),
                'name' => trim(($merge->merged['first_name'] ?? '') . ' ' . ($merge->merged['last_name'] ?? '')) ?: null,
            ],
            'created_at' => $merge->created_at->toIso8601String(),
        ]);

        return [
            'email' => $user->email,
            'google' => $user->google_sub !== null,
            'has_password' => $user->password_hash !== null,
            'two_factor' => $user->hasTwoFactor(),
            'sessions' => $this->sessions->count($user),
            'merges' => $merges->values()->all(),
        ];
    }
}
