<?php

declare(strict_types=1);

namespace App\Modules\Imports\Records;

use App\Modules\Orders\Enums\OrderType;
use Illuminate\Support\Carbon;

/**
 * A discount code the other bot took at checkout (discount_codes), with the uses it has left. What the shop's codes
 * cannot say of it (a code only for its agents, say) is in `$note`, which the code keeps.
 */
final class ImportedDiscountCode
{
    /**
     * @param list<OrderType>|null $orderTypes What it is taken for; null for everything
     * @param list<string>|null $planKeys The plans it holds for (ImportedPlan::$key); null for any
     * @param list<string>|null $panelKeys The panels it holds for (ImportedPanel::$key); null for any
     */
    public function __construct(
        public readonly string $key,
        public readonly string $code,
        /** 'percent', 'fixed' or 'free' (discount_codes.type). */
        public readonly string $type,
        /** A percent, or Toman for a fixed one, a decimal string; '0' for a free one. */
        public readonly string $value,
        /** The uses it has left; null for no limit. 0 is a code used up, which the core does not bring. */
        public readonly ?int $usesLeft = null,
        /** How often one customer may use it; null for no limit. */
        public readonly ?int $perUser = null,
        public readonly bool $firstPurchaseOnly = false,
        /** When it stops; null for never. One already past is not brought. */
        public readonly ?\DateTimeImmutable $endsAt = null,
        public readonly ?array $orderTypes = null,
        public readonly ?array $planKeys = null,
        public readonly ?array $panelKeys = null,
        public readonly bool $enabled = true,
        public readonly ?string $note = null,
    ) {}

    /** It has no use left: the core does not bring it. */
    public function usedUp(): bool
    {
        return $this->usesLeft !== null && $this->usesLeft <= 0;
    }

    /** Its end has come: the core does not bring it. */
    public function ended(): bool
    {
        return $this->endsAt !== null && $this->endsAt <= Carbon::now();
    }
}
