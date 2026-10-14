<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

/**
 * The top-up amounts drawn at random for the bot's «افزایش موجودی» and the website's wallet, beside the presets
 * (WalletSettings::randomButtons()): as many as the shop asks, each a multiple of its rounding within its least and most
 * — never below the smallest top-up, nor above the largest —, distinct, none a preset, ascending. Drawn anew each time.
 */
final class TopUpAmounts
{
    /** Up to this many choices are drawn from a shuffled list; a wider range by picks, each a few tries. */
    private const LISTED_MOST = 2_000;

    public function __construct(private readonly WalletSettings $settings) {}

    /** @return list<int> The amounts drawn, in Toman, ascending; none when the shop asks for none or its range holds none. */
    public function random(): array
    {
        ['count' => $count, 'min' => $min, 'max' => $max, 'round' => $round] = $this->settings->randomButtons();
        if ($count <= 0 || $round <= 0) {
            return [];
        }
        // The multiples of `round` (as its multipliers) from the least, rounded up, to the most, rounded down.
        $first = intdiv(max($min, $this->settings->topUpMin()) + $round - 1, $round);
        $last = intdiv(min($max, WalletSettings::TOPUP_MAX), $round);
        if ($last < $first) {
            return [];
        }
        $presets = array_flip($this->settings->topUpPresets());

        $drawn = [];
        if ($last - $first < self::LISTED_MOST) {
            $choices = array_values(array_filter(range($first, $last), static fn(int $times): bool => !isset($presets[$times * $round])));
            shuffle($choices);
            foreach (array_slice($choices, 0, $count) as $times) {
                $drawn[] = $times * $round;
            }
        } else {
            for ($tries = 0; count($drawn) < $count && $tries < $count * 10; $tries++) {
                $amount = random_int($first, $last) * $round;
                if (!isset($presets[$amount]) && !in_array($amount, $drawn, true)) {
                    $drawn[] = $amount;
                }
            }
        }
        sort($drawn);

        return $drawn;
    }
}
