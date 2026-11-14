<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Services;

use App\Modules\Discounts\DTO\AutomaticDiscounts;
use App\Modules\Discounts\Models\Sale;
use App\Modules\Users\Models\User;

/**
 * Reads the automatic discounts a customer has now: the running sales, their own percent and their loyalty tier's. Read
 * once (one query for the sales, two for the tier when the shop has tiers) and judged for any number of prices. One who
 * goes without the customers' perks (Perks) keeps only their own percent.
 */
final class Discounts
{
    public function __construct(
        private readonly Loyalty $loyalty,
        private readonly Perks $perks,
    ) {}

    /** The customer's automatic discounts; a visitor who has not signed in (null) has only the sales. */
    public function for(?User $user): AutomaticDiscounts
    {
        if ($user === null) {
            return new AutomaticDiscounts(Sale::running()->orderBy('id')->get()->all(), 0, null);
        }
        $withheld = $this->perks->withheld($user);

        return new AutomaticDiscounts(
            $withheld ? [] : Sale::running()->orderBy('id')->get()->all(),
            (int) $user->discount_percent,
            $withheld ? null : $this->loyalty->tierOf($user, $this->loyalty->tiers()),
        );
    }
}
