<?php

declare(strict_types=1);

namespace App\Modules\Rewards\DTO;

use App\Modules\Rewards\Models\WheelPrize;
use Illuminate\Support\Carbon;

/** The lucky wheel as one customer finds it (Services\LuckyWheel::state()); `nextAt` null means they may spin now. */
final readonly class WheelState
{
    /** @param list<WheelPrize> $prizes */
    public function __construct(
        public array $prizes,
        public int $cost,
        public int $cooldownHours,
        public ?Carbon $nextAt,
    ) {}
}
