<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\DTO;

use App\Support\Money;

/**
 * What a shop sells of one extra kind (Enums\ExtraKind): the unit price (or none), the amounts offered as buttons (each
 * at its own price or the unit price), and — with a unit price — the least and most a customer may type. With neither,
 * none of it is sold. Devices are priced for PERIOD_DAYS and charged for the days left of a service's period (prorated()).
 */
final readonly class ExtraOffer
{
    /** The days a device's prices are set for. */
    public const PERIOD_DAYS = 30;

    /**
     * @param array<int, string|null> $options Each amount offered => its own price, or null for the unit price; smallest
     *                                         first
     */
    public function __construct(
        /** A GB's, a day's or a device's price (Toman, a Money string); null: only amounts priced their own sell. */
        public ?string $price,
        public array $options,
        /** The least and the most a customer may type, while a unit price prices it. */
        public int $min,
        public int $max,
        /** The days its prices are charged for, of the PERIOD_DAYS they are set for; null: at the prices set. */
        public ?int $days = null,
    ) {}

    /** Whether it sells anything: an amount offered at a price, or any amount typed at the unit price. */
    public function sells(): bool
    {
        return $this->typed() || $this->choices() !== [];
    }

    /** Whether a customer may type any amount between `min` and `max`: a unit price prices it. */
    public function typed(): bool
    {
        return $this->price !== null && $this->min <= $this->max;
    }

    /**
     * What `$quantity` costs: an amount offered at its own price or the unit price; any other amount at the unit price
     * while it may be typed — prorated to `days`, rounded up to whole Toman. Null when not sold.
     */
    public function priceOf(int $quantity): ?string
    {
        if (array_key_exists($quantity, $this->options)) {
            return $this->charged($this->options[$quantity] ?? ($this->price === null ? null : Money::times($this->price, $quantity)));
        }

        return $this->typed() && $quantity >= $this->min && $quantity <= $this->max && $this->price !== null ? $this->charged(Money::times($this->price, $quantity)) : null;
    }

    /** The unit price as charged — prorated to `days`, rounded up —; null without one. */
    public function unitPrice(): ?string
    {
        return $this->charged($this->price);
    }

    /**
     * The amounts offered as buttons, each with what it costs, the smallest first.
     *
     * @return list<array{quantity: int, price: string}>
     */
    public function choices(): array
    {
        $choices = [];
        foreach (array_keys($this->options) as $quantity) {
            $price = $this->priceOf($quantity);
            if ($price !== null) {
                $choices[] = ['quantity' => $quantity, 'price' => $price];
            }
        }

        return $choices;
    }

    /**
     * The offer held to `$most` (what an agent's pool may still sell, the days a service may still take): the amounts
     * above it go, and so does the room to type above it.
     */
    public function upTo(int $most): self
    {
        return new self(
            $this->price,
            array_filter($this->options, static fn(int $quantity): bool => $quantity <= $most, ARRAY_FILTER_USE_KEY),
            $this->min,
            min($this->max, $most),
            $this->days,
        );
    }

    /** The offer charged for `$days` of the PERIOD_DAYS its prices are set for: devices for what is left of a period. */
    public function prorated(int $days): self
    {
        return new self($this->price, $this->options, $this->min, $this->max, $days);
    }

    /** A price as charged: prorated to `days` while it is set. */
    private function charged(?string $price): ?string
    {
        return $price === null || $this->days === null ? $price : Money::shareOf($price, $this->days, self::PERIOD_DAYS);
    }
}
