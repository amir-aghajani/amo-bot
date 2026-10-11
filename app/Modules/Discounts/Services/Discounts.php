<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Services;

use App\Modules\Discounts\DTO\AutomaticDiscounts;
use App\Modules\Discounts\Models\Sale;
use App\Modules\Users\Models\User;

/**
 * Reads the automatic discounts a customer has now: the running sales, their own percent and their loyalty tier's. Read
 * once (one query for the sales, two for the tier when the shop has tiers) and judged for any number of prices.
 */
final class Discounts
{
    public function __construct(private readonly Loyalty $loyalty) {}

    /** The customer's automatic discounts; a visitor who has not signed in (null) has only the sales. */
    public function for(?User $user): AutomaticDiscounts
    {
        return new AutomaticDiscounts(
            Sale::running()->orderBy('id')->get()->all(),
            $user === null ? 0 : (int) $user->discount_percent,
            $this->loyalty->tierOf($user),
        );
    }
}
