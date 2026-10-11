<?php

declare(strict_types=1);

namespace App\Modules\Rewards\DTO;

use App\Modules\Rewards\Models\WheelSpin;

/**
 * A spin (Services\LuckyWheel::spin()): the spin as kept, the slices' labels and the index it landed on, and the prize
 * given or why not.
 */
final readonly class Spun
{
    /** @param list<string> $slices */
    public function __construct(
        public WheelSpin $spin,
        public array $slices,
        public int $landed,
        public ?Given $given,
        /** Why the prize was not given, worded for the customer; null — given. */
        public ?string $failure = null,
        /** Whether the spin's cost went back to the wallet. */
        public bool $refunded = false,
    ) {}
}
