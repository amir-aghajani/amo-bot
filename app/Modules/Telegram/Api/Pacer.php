<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Api;

use App\Core\Support\Sleeper;

/**
 * The pace of bulk sends (broadcasts, reminders): PER_SECOND calls a second, under Telegram's ~30 a second for a bot,
 * leaving room for answers to customers. Each call sleeps its share of a second through the Sleeper (a no-op in tests).
 */
final class Pacer
{
    /** Bulk calls a second. */
    public const PER_SECOND = 20;

    public function __construct(private readonly Sleeper $sleeper) {}

    /** After one call of a bulk send: its share of a second. */
    public function pace(): void
    {
        $this->sleeper->usleep(intdiv(1_000_000, self::PER_SECOND));
    }
}
