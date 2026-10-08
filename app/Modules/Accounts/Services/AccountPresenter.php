<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Modules\Users\Models\User;

/** The customer's account as the shop's website shows it to them: `GET /me`, and what a sign-in answers with. */
final class AccountPresenter
{
    /**
     * Their names, their ways in — their Telegram account (its id and handle; none for one who signed up on the
     * website), their email, whether a Google account and a password sign them in, whether the password sign-in asks a
     * second step —, the phone they shared with the bot, the wallet's balance (Toman, the ledger's last line) and since
     * when they are the shop's customer.
     *
     * @return array{id: int, first_name: string|null, last_name: string|null, telegram: array{id: int, username: string|null}|null, email: string|null, google: bool, has_password: bool, two_factor: bool, phone: string|null, balance: string, created_at: string}
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
            'created_at' => $user->created_at->toIso8601String(),
        ];
    }
}
