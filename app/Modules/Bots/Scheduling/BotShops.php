<?php

declare(strict_types=1);

namespace App\Modules\Bots\Scheduling;

use App\Core\Scheduling\Shops;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;

/**
 * The scheduler's shops are the bots': the main bot's always (its rows are there, a token or not), then every agent's
 * bot that runs (Bots::shops()) — each worked in with CurrentBot, and the shop as a whole everywhere().
 */
final class BotShops implements Shops
{
    public function __construct(private readonly Bots $bots) {}

    public function ids(): array
    {
        return array_values($this->bots->shops()->map(static fn(Bot $bot): int => $bot->id)->all());
    }

    public function in(int $shop, \Closure $work): void
    {
        CurrentBot::run($shop, $work);
    }

    public function everywhere(\Closure $work): void
    {
        CurrentBot::everywhere($work);
    }
}
