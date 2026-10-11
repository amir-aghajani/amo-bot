<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

/**
 * Where scheduled work runs: in one shop (its bot, rules and texts) or across all shops at once. Implemented by the
 * Bots module (BotShops), so Core names no module.
 */
interface Shops
{
    /** @return list<int> The shops whose own work the scheduler does, in order: the main one first. */
    public function ids(): array;

    /** @param \Closure(): void $work Run in that shop. */
    public function in(int $shop, \Closure $work): void;

    /** @param \Closure(): void $work Run with no shop scope. */
    public function everywhere(\Closure $work): void;
}
