<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

/**
 * Where scheduled work runs: a shop's own task once in every shop, in that shop's state — its rules, its texts, its
 * bot —, and the work that is the shop's as a whole across all of them at once. What a shop is, is the Bots module's
 * to say (Bots\Scheduling\BotShops); the scheduler only goes round them.
 */
interface Shops
{
    /** @return list<int> The shops whose own work the scheduler does, in order: the main one first. */
    public function ids(): array;

    /** @param \Closure(): void $work Done in that shop. */
    public function in(int $shop, \Closure $work): void;

    /** @param \Closure(): void $work Done across every shop at once. */
    public function everywhere(\Closure $work): void;
}
