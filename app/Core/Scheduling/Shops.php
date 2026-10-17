<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

/**
 * Where work runs: scheduled work in one shop (its bot, rules and texts) or across all shops at once, and the shop the
 * code is in now (the change feed counts a write for it). Implemented by the Bots module (BotShops), so Core names no
 * module.
 */
interface Shops
{
    /** The shop the code works in now; null while it works across every shop. */
    public function current(): ?int;

    /** @return list<int> The shops whose own work the scheduler does, in order: the main one first. */
    public function ids(): array;

    /** @param \Closure(): void $work Run in that shop. */
    public function in(int $shop, \Closure $work): void;

    /** @param \Closure(): void $work Run with no shop scope. */
    public function everywhere(\Closure $work): void;
}
