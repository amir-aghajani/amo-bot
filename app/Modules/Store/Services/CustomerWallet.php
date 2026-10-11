<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Store\Presenters\WalletPresenter;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\WalletSettings;
use App\Support\Money;

/**
 * A customer's wallet as their website shows it, like the bot's «کیف پول»: its balance and what it can pay, the top-up
 * bounds and presets (WalletSettings, as OrderService::topUpAllowed() applies them), and its ledger.
 */
final class CustomerWallet
{
    public function __construct(private readonly WalletSettings $settings) {}

    /**
     * The balance (read with the customer once: User::balanceColumn()), an agent's credit, what the two can pay, and the
     * top-up's bounds and presets.
     *
     * @return array{balance: string, credit: string, spendable: string, top_up: array{min: string, max: string, presets: list<string>}}
     */
    public function present(User $customer): array
    {
        $user = User::query()->addSelect(User::balanceColumn())->findOrFail($customer->id);

        return WalletPresenter::balance($user) + ['top_up' => [
            'min' => Money::normalize($this->settings->topUpMin()),
            'max' => Money::normalize(WalletSettings::TOPUP_MAX),
            'presets' => array_map(static fn(int $amount): string => Money::normalize($amount), $this->settings->topUpPresets()),
        ]];
    }

    /** A page of the customer's ledger, newest line first. */
    public function ledger(User $customer, PageRequest $request): Page
    {
        return Page::fetch(WalletTransaction::query()->where('user_id', $customer->id)->latest('id'), $request, WalletPresenter::transaction(...));
    }
}
