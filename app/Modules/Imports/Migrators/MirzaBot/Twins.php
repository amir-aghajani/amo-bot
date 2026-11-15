<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\MirzaBot;

/**
 * How a bot of MirzaBot's line prices its agents: apart, as products of their own. A product only its agents see, beside a
 * customers' one of the same traffic, days and panels (its twin), is that plan's agent price — the plain agents' (n) over
 * the advanced ones' (n2) — and what was sold under it was sold under that plan. An agents' product with no twin is a
 * plan of its own, only for agents.
 */
final class Twins
{
    /** The agents' levels, the one whose price a plan keeps first. */
    public const TIERS = ['n', 'n2'];

    /**
     * Each agents' product of a level takes the first customers' twin no product of that level took yet — of its own
     * category when there is one —, in the order `$products` come in (the bot's own, where its migrator keeps one). Each
     * says who sees it, its agents' level, what makes two products twins, its category and its price.
     *
     * @param array<int, array{forCustomers: bool, tier: ?string, twin: string, category: ?string, price: string}> $products
     * @return array{array<int, int>, array<int, array<string, string>>} Each agents' product's twin; each twin's agent prices by level
     */
    public static function pair(array $products): array
    {
        $twinOf = [];
        $agentPrices = [];
        foreach (self::TIERS as $tier) {
            foreach ($products as $at => $product) {
                if ($product['forCustomers'] || $product['tier'] !== $tier) {
                    continue;
                }
                $twins = array_filter($products, static fn(array $other, int $place): bool => $other['forCustomers'] && $other['twin'] === $product['twin'] && !isset($agentPrices[$place][$tier]), ARRAY_FILTER_USE_BOTH);
                $twin = array_key_first(array_filter($twins, static fn(array $other): bool => $other['category'] === $product['category'])) ?? array_key_first($twins);
                if ($twin !== null) {
                    $twinOf[$at] = $twin;
                    $agentPrices[$twin][$tier] = $product['price'];
                }
            }
        }

        return [$twinOf, $agentPrices];
    }
}
