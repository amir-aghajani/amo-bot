<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Accounts\Models\AccountMerge;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Agency\Services\AgencyDirectory;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;

/**
 * One customer as their page in either panel shows them (`GET /users/{id}`, the shop's own customer): their row as the
 * users table has it, the referral program's facts about them — whose link brought them, how many their own link brought
 * and what those earned them —, an agent of the main bot's agency as the agents list shows it (their level and credit,
 * their bot and what it sold), and their account on the shop's website (account()): their ways in, whether its password
 * sign-in asks a second step, the devices signed in, and the accounts merged into it. Their services, orders and payments
 * are those lists' own, narrowed to them (`user=`).
 */
final class CustomerProfile
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly ReferralService $referrals,
        private readonly AgencyDirectory $agency,
        private readonly CustomerSessions $sessions,
    ) {}

    /** @return array{user: array<string, mixed>, referral: array{referrer: array<string, mixed>|null, referrals: int, earned: string}, agency: array<string, mixed>|null, account: array<string, mixed>} */
    public function present(User $user): array
    {
        $referrer = $user->referrer;

        return [
            'user' => $this->users->presentWithCounts($user),
            'referral' => ['referrer' => $referrer === null ? null : UserDirectory::presentRef($referrer)] + $this->referrals->statsFor($user),
            // Agents are the main bot's customers: in another shop no customer is one.
            'agency' => $user->isAgent() ? $this->agency->presentAgent($this->agency->agent($user->id)) : null,
            'account' => $this->account($user),
        ];
    }

    /**
     * The customer's account on the shop's website, as support reads it: its email, whether a Google account and a
     * password sign it in, whether the password sign-in asks a second step, how many devices are signed in, and the
     * accounts merged into it, newest first — each as it was signed in and called, and when the customer merged it (the
     * one way two accounts are made one: from their website).
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
