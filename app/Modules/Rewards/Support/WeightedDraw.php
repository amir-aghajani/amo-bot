<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Support;

/** A draw by weight — the wheel's slices, the nightly draw's entries: each key owns a stretch as long as its weight. */
final class WeightedDraw
{
    /**
     * A key drawn at random (random_int), each with its weight's share of the chances.
     *
     * @param non-empty-array<int, int> $weights Positive weights by key
     */
    public static function draw(array $weights): int
    {
        return self::pick($weights, random_int(1, array_sum($weights)));
    }

    /**
     * The key a roll of 1 to the weights' sum lands on.
     *
     * @param non-empty-array<int, int> $weights Positive weights by key
     */
    public static function pick(array $weights, int $roll): int
    {
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return (int) array_key_last($weights);
    }
}
