<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Modules\Store\Presenters\RecipientPresenter;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransfer;
use App\Modules\Users\Services\WalletTransfers;

/**
 * «انتقال موجودی» as a customer's website offers it (Users\Services\WalletTransfers, the bot's rules): whether they may
 * send money and how much now, and a transfer as the answer shows it — its recipient as RecipientPresenter does.
 */
final class CustomerWalletTransfers
{
    public function __construct(private readonly WalletTransfers $transfers) {}

    /**
     * What the customer may send now: the shop's least and most, its daily cap and what it leaves them today (both null
     * without a cap), and the most they may send at all; null while the shop offers none.
     *
     * @return array{min: string, max: string, daily_cap: string|null, left_today: string|null, most: string}|null
     */
    public function offer(User $customer): ?array
    {
        if (!$this->transfers->offered()) {
            return null;
        }
        [$min, $max] = $this->transfers->bounds();

        return [
            'min' => $min,
            'max' => $max,
            'daily_cap' => $this->transfers->dailyCap(),
            'left_today' => $this->transfers->leftToday($customer),
            'most' => $this->transfers->most($customer),
        ];
    }

    /** @return array{id: int, amount: string, recipient: array{name: string|null, username: string|null}, created_at: string} */
    public static function present(WalletTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'amount' => $transfer->amount,
            'recipient' => RecipientPresenter::present($transfer->recipient),
            'created_at' => $transfer->created_at->toIso8601String(),
        ];
    }
}
