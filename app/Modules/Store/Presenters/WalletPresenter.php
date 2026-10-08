<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;

/**
 * A customer's wallet as their website shows it: what is in it and what it can pay, and the lines of its ledger — the
 * lines the bot's «کیف پول» shows them too. Amounts are Toman, as decimal strings.
 */
final class WalletPresenter
{
    /**
     * The balance (the ledger's last line — below zero an agent's debt), an agent's credit (none for anyone else) and
     * what the two can pay now (User::spendable()). Read the balance with the customer (User::balanceColumn()), or it is
     * read twice.
     *
     * @return array{balance: string, credit: string, spendable: string}
     */
    public static function balance(User $user): array
    {
        return [
            'balance' => $user->balance(),
            'credit' => $user->credit(),
            'spendable' => $user->spendable(),
        ];
    }

    /** @return array{id: int, type: string, amount: string, balance_after: string, description: string|null, created_at: string} */
    public static function transaction(WalletTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'amount' => $transaction->amount,
            'balance_after' => $transaction->balance_after,
            'description' => $transaction->description,
            'created_at' => $transaction->created_at->toIso8601String(),
        ];
    }
}
