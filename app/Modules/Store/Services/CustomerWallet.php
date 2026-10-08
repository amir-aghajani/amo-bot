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
 * A customer's wallet on the shop's website, as the bot's «کیف پول» has it: what is in it and what it can pay, what a
 * top-up may be — the bot's «افزایش موجودی» bounds, the shop's wallet settings (WalletSettings: the smallest top-up
 * OrderService::topUpAllowed() takes and the largest, the amounts offered as buttons) —, and its ledger, a page at a time.
 */
final class CustomerWallet
{
    public function __construct(private readonly WalletSettings $settings) {}

    /**
     * The balance read with the customer once (User::balanceColumn() — what it can pay counts it again otherwise), an
     * agent's credit, what the two can pay, and the top-up's bounds and its presets.
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

    /** The customer's ledger, newest line first, a page of it — the lines the bot's «کیف پول» shows. */
    public function ledger(User $customer, PageRequest $request): Page
    {
        return Page::fetch(WalletTransaction::query()->where('user_id', $customer->id)->latest('id'), $request, WalletPresenter::transaction(...));
    }
}
