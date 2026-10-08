<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Api;

use App\Core\Support\Sleeper;

/**
 * The pace of the bot's bulk sends — a broadcast, the reminders —: PER_SECOND calls a second at most, under Telegram's
 * limit of about 30 messages a second for a bot, so its answers to the customers writing to it meanwhile keep their room.
 * Each call is followed by its share of a second, slept through the shop's Sleeper (the tests' does not sleep).
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
