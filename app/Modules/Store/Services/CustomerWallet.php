<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Store\Presenters\WalletPresenter;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\TopUpAmounts;
use App\Modules\Users\Services\WalletSettings;
use App\Support\Money;

/**
 * A customer's wallet as their website shows it, like the bot's «کیف پول»: its balance and what it can pay, the top-up
 * bounds and presets (WalletSettings, as OrderService::topUpAllowed() applies them), and its ledger.
 */
final class CustomerWallet
{
    public function __construct(
        private readonly WalletSettings $settings,
        private readonly TopUpAmounts $random,
    ) {}

    /**
     * The balance (read with the customer once: User::balanceColumn()), an agent's credit, what the two can pay, and the
     * top-up's bounds, presets and amounts drawn at random for this answer (TopUpAmounts), as the bot's screen offers them.
     *
     * @return array{balance: string, credit: string, spendable: string, top_up: array{min: string, max: string, presets: list<string>, random: list<string>}}
     */
    public function present(User $customer): array
    {
        $user = User::query()->addSelect(User::balanceColumn())->findOrFail($customer->id);

        return WalletPresenter::balance($user) + ['top_up' => [
            'min' => Money::normalize($this->settings->topUpMin()),
            'max' => Money::normalize(WalletSettings::TOPUP_MAX),
            'presets' => self::amounts($this->settings->topUpPresets()),
            'random' => self::amounts($this->random->random()),
        ]];
    }

    /**
     * @param list<int> $amounts Toman
     * @return list<string> As the API writes amounts
     */
    private static function amounts(array $amounts): array
    {
        return array_map(static fn(int $amount): string => Money::normalize($amount), $amounts);
    }

    /** A page of the customer's ledger, newest line first. */
    public function ledger(User $customer, PageRequest $request): Page
    {
        return Page::fetch(WalletTransaction::query()->where('user_id', $customer->id)->latest('id'), $request, WalletPresenter::transaction(...));
    }
}
