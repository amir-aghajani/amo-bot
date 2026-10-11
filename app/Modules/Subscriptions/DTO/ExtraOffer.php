<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\DTO;

use App\Support\Money;

/**
 * What a shop sells of one extra kind (Enums\ExtraKind): the unit price (or none), the amounts offered as buttons (each
 * at its own price or the unit price), and — with a unit price — the least and most a customer may type. With neither,
 * none of it is sold.
 */
final readonly class ExtraOffer
{
    /**
     * @param array<int, string|null> $options Each amount offered => its own price, or null for the unit price; smallest
     *                                         first
     */
    public function __construct(
        /** The price of one GB or one day (Toman, a Money string); null when only amounts with their own price are sold. */
        public ?string $price,
        public array $options,
        /** The least and the most a customer may type, while a unit price prices it. */
        public int $min,
        public int $max,
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
     * while it may be typed. Null when not sold.
     */
    public function priceOf(int $quantity): ?string
    {
        if (array_key_exists($quantity, $this->options)) {
            return $this->options[$quantity] ?? ($this->price === null ? null : Money::times($this->price, $quantity));
        }

        return $this->typed() && $quantity >= $this->min && $quantity <= $this->max && $this->price !== null ? Money::times($this->price, $quantity) : null;
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
     * The offer held to `$most` (what an agent's pool may still sell): the amounts above it go, and so does the room to
     * type above it.
     */
    public function upTo(int $most): self
    {
        return new self(
            $this->price,
            array_filter($this->options, static fn(int $quantity): bool => $quantity <= $most, ARRAY_FILTER_USE_KEY),
            $this->min,
            min($this->max, $most),
        );
    }
}
