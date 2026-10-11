<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Models\Concerns;

use App\Modules\Discounts\Enums\CodeState;
use App\Modules\Orders\Enums\OrderType;

/**
 * What a discount code or a sale applies to, and when: the kinds of order it names (`order_types`, null = every kind a
 * customer buys for themselves), the plans it names (`plan_ids`, null = every plan), and its window (`starts_at`,
 * `ends_at`, null = open on that side) while it is `enabled`.
 */
trait DiscountScope
{
    /** Whether it applies to an order of `$kind`. */
    public function takesKind(OrderType $kind): bool
    {
        return $this->order_types === null ? $kind->forThemselves() : in_array($kind->value, $this->order_types, true);
    }

    /** Whether it applies to an order of plan `$planId` (null: a service with no plan, which only "every plan" reaches). */
    public function takesPlan(?int $planId): bool
    {
        return $this->plan_ids === null || ($planId !== null && in_array($planId, $this->plan_ids, true));
    }

    /** How its switch and window stand now: off, not started, ended, or active. */
    public function windowState(): CodeState
    {
        $now = now();

        return match (true) {
            !$this->enabled => CodeState::Off,
            $this->starts_at !== null && $this->starts_at > $now => CodeState::Scheduled,
            $this->ends_at !== null && $this->ends_at <= $now => CodeState::Ended,
            default => CodeState::Active,
        };
    }
}
