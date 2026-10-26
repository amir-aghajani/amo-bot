<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Modules\Users\Models\User;

/** The customer's account as their website shows it to them: `GET /me`, and what a sign-in answers. */
final class AccountPresenter
{
    /**
     * Their names, their ways in (a Telegram account, null for a website-only customer; an email; whether Google and a
     * password sign them in; whether two-factor sign-in is on), their phone, their balance and since when they are a
     * customer.
     *
     * @return array{id: int, first_name: string|null, last_name: string|null, telegram: array{id: int, username: string|null}|null, email: string|null, google: bool, has_password: bool, two_factor: bool, phone: string|null, balance: string, created_at: string|null}
     */
    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'telegram' => $user->telegram_id === null ? null : ['id' => $user->telegram_id, 'username' => $user->username],
            'email' => $user->email,
            'google' => $user->google_sub !== null,
            'has_password' => $user->password_hash !== null,
            'two_factor' => $user->hasTwoFactor(),
            'phone' => $user->phone,
            'balance' => $user->balance(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
