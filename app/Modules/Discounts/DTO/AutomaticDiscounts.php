<?php

declare(strict_types=1);

namespace App\Modules\Discounts\DTO;

use App\Modules\Discounts\Enums\DiscountSource;
use App\Modules\Discounts\Models\LoyaltyTier;
use App\Modules\Discounts\Models\Sale;
use App\Modules\Orders\Enums\OrderType;
use App\Support\Money;
use App\Support\Persian;

/**
 * One customer's automatic discounts — the running sales, their own percent, their loyalty tier's — read once
 * (Services\Discounts::for()). Only the largest applies to a price (best()): they are never added together.
 */
final readonly class AutomaticDiscounts
{
    /**
     * @param list<Sale> $sales The shop's sales running now
     * @param int $personal The customer's own percent (`users.discount_percent`), 0 none
     */
    public function __construct(
        public array $sales,
        public int $personal,
        public ?LoyaltyTier $tier,
    ) {}

    /**
     * The automatic discount that takes the most off `$base` for an order of `$kind` on plan `$planId` (a renewal's or an
     * extra's: its service's plan; null reaches only a sale of every plan). Null when none takes anything: a kind no
     * discount applies to, a free price, nothing running.
     */
    public function best(?int $planId, OrderType $kind, string $base): ?DiscountLine
    {
        if (!$kind->forThemselves() || !Money::isPositive($base)) {
            return null;
        }

        $best = null;
        foreach ($this->sales as $sale) {
            if ($sale->takesPlan($planId) && $sale->takesKind($kind)) {
                $best = self::larger($best, self::line(DiscountSource::Sale, $sale->name . ' ' . self::percent($sale->percent), $base, $sale->percent, $sale->id, $sale->ends_at));
            }
        }
        if ($this->personal > 0) {
            $best = self::larger($best, self::line(DiscountSource::Personal, 'تخفیف اختصاصی شما ' . self::percent($this->personal), $base, $this->personal));
        }
        if ($this->tier !== null && $this->tier->discount_percent > 0) {
            $best = self::larger($best, self::line(DiscountSource::Tier, 'سطح ' . $this->tier->name . ' ' . self::percent($this->tier->discount_percent), $base, $this->tier->discount_percent, $this->tier->id));
        }

        return $best !== null && Money::isPositive($best->amount) ? $best : null;
    }

    /** A percent as the customer reads it: «۲۰٪». */
    public static function percent(int $percent): string
    {
        return Persian::number($percent) . '٪';
    }

    private static function line(DiscountSource $source, string $label, string $base, int $percent, ?int $id = null, ?\DateTimeInterface $endsAt = null): DiscountLine
    {
        return new DiscountLine($source, $label, Money::percentOf($base, min(100, $percent)), $percent, $id, $endsAt);
    }

    /** Of two discounts, the one that takes more off; the first of two equal ones. */
    private static function larger(?DiscountLine $best, DiscountLine $line): DiscountLine
    {
        return $best === null || Money::compare($line->amount, $best->amount) > 0 ? $line : $best;
    }
}
