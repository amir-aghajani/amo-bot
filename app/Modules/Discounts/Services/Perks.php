<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Services;

use App\Modules\Users\Models\User;

/**
 * Who goes without the shop's customer perks, whatever they buy: one who pays agent prices (User::paysAgentPrices(): an
 * agent, or in a «همکار» group) while «همکارها فقط با قیمت همکاری» is on (DiscountSettings). Withheld from them: the
 * automatic discounts but their own (a sale, their loyalty tier — their own discount is the owner's choice for them, and
 * stays), discount codes, first-purchase prices, cashback, the lucky wheel and the nightly draw. A plan's agent price is
 * what they pay for it; where none applies (a plan without one, extras, a built service), the regular price. Asked in
 * the current shop.
 */
final class Perks
{
    /** A code typed by one who goes without the perks. */
    public const NO_CODES = 'کد تخفیف برای حساب همکارها فعال نیست.';

    public function __construct(private readonly DiscountSettings $settings) {}

    /** Whether `$user` goes without the customers' perks; never a visitor, who has not signed in (null). */
    public function withheld(?User $user): bool
    {
        return $user !== null && $this->settings->agentsWithoutPerks() && $user->paysAgentPrices();
    }
}
