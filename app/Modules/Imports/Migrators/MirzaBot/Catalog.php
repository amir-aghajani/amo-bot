<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\MirzaBot;

use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Staging\Staged;

/**
 * MirzaBot's products as this shop's plans. Each product is for one audience (`agent`: customers, `f`, or one of the
 * agents' levels, who see only theirs) and one panel or every one (`Location`), hidden from some (`hide_panel`); an
 * agents' product pairs with its customers' twin as that plan's agent price (Twins). One MirzaBot marked sold out
 * («ناموجود») comes switched off.
 */
final class Catalog
{
    /** Every panel, as `Location` names it. */
    private const EVERY_PANEL = '/all';

    /** A product MirzaBot stopped selling (`status_product`). */
    private const SOLD_OUT = 'disable';

    /** A product's traffic reset that is none (`data_limit_reset`); any other resets it on a schedule. */
    private const NO_RESET = 'no_reset';

    /** @param array<int, ImportedPlan> $plans By their place in MirzaBot's order, from 1 */
    private function __construct(
        private readonly array $plans,
        /** Which plan a product, or a service, was sold under. */
        public readonly Sold $sold,
        /** Whether some product had a price only for advanced agents (n2), which no plan keeps apart. */
        public readonly bool $seniorPrices,
        /** How many products had options no plan here keeps: newcomers only, traffic reset on a schedule. */
        public readonly int $withOptions,
        /** How many plans come switched off as MirzaBot had stopped selling them. */
        public readonly int $soldOut,
    ) {}

    /** The staged copy's products, paired and keyed (`code_product`, else the product's id). */
    public static function of(Staged $staged, Panels $panels): self
    {
        /** @var array<int, array{row: array<string, ?string>, key: string, panels: list<string>|null, forCustomers: bool, tier: ?string, twin: string, category: ?string, price: string}> $products */
        $products = [];
        $keys = [];
        foreach ($staged->rows('product') as $seq => $row) {
            $level = strtolower(trim((string) ($row['agent'] ?? '')));
            $panelKeys = self::panelKeys($row, $panels);
            $soldOn = $panelKeys ?? [self::EVERY_PANEL];
            sort($soldOn);
            // MirzaBot's codes are four random hex digits: one that repeats is the product's id instead.
            $code = Cell::text($row['code_product'] ?? null);
            $key = $code !== null && !isset($keys[$code]) ? $code : '#' . (Cell::text($row['id'] ?? null) ?? $seq);
            $keys[$key] = true;
            $products[$seq] = [
                'row' => $row,
                'key' => $key,
                'panels' => $panelKeys,
                'forCustomers' => !in_array($level, Twins::TIERS, true),
                'tier' => in_array($level, Twins::TIERS, true) ? $level : null,
                'twin' => implode('|', [Cell::number($row['Volume_constraint'] ?? null) ?? '0', Cell::whole($row['Service_time'] ?? null), implode(',', $soldOn)]),
                'category' => Cell::text($row['category'] ?? null),
                'price' => Cell::amount($row['price_product'] ?? null),
            ];
        }
        // In MirzaBot's own order — by display order, then by id —, which the pairing goes by too.
        $order = static fn(int $seq): array => [Cell::whole($products[$seq]['row']['display_order'] ?? null), Cell::whole($products[$seq]['row']['id'] ?? null), $seq];
        uksort($products, static fn(int $a, int $b): int => $order($a) <=> $order($b));
        [$twinOf, $agentPrices] = Twins::pair($products);

        $plans = [];
        $sold = [];
        $seniorPrices = false;
        $withOptions = 0;
        $soldOut = 0;
        // The customers' products first: a name both carry is the customers' plan.
        uasort($products, static fn(array $a, array $b): int => $b['forCustomers'] <=> $a['forCustomers']);
        foreach ($products as $seq => $product) {
            $row = $product['row'];
            $name = Cell::text($row['name_product'] ?? null);
            $traffic = Cell::number($row['Volume_constraint'] ?? null) ?? '0';
            $days = Cell::whole($row['Service_time'] ?? null);
            $planKey = isset($twinOf[$seq]) ? $products[$twinOf[$seq]]['key'] : $product['key'];
            if (!isset($twinOf[$seq])) {
                $selling = strtolower(trim((string) ($row['status_product'] ?? ''))) !== self::SOLD_OUT;
                $plans[$seq] = new ImportedPlan(
                    key: $product['key'],
                    name: $name ?? 'پلن ' . $product['key'],
                    category: $product['category'],
                    price: $product['price'],
                    agentPrice: $agentPrices[$seq]['n'] ?? $agentPrices[$seq]['n2'] ?? null,
                    trafficGb: $traffic,
                    durationDays: max(0, $days),
                    note: Cell::text($row['note'] ?? null),
                    panelKeys: $product['panels'],
                    agentsOnly: !$product['forCustomers'],
                    selling: $selling,
                );
                $soldOut += $selling ? 0 : 1;
            }
            $sold[] = ['key' => $product['key'], 'name' => $name, 'volume' => $traffic, 'days' => $days, 'panels' => $product['panels'], 'plan' => $planKey];
            $seniorPrices = $seniorPrices || (!$product['forCustomers'] && $product['tier'] === 'n2');
            $reset = Cell::text($row['data_limit_reset'] ?? null);
            $withOptions += trim((string) ($row['one_buy_status'] ?? '')) === '1' || ($reset !== null && $reset !== self::NO_RESET) ? 1 : 0;
        }
        uksort($plans, static fn(int $a, int $b): int => $order($a) <=> $order($b));
        $plans = $plans === [] ? [] : array_combine(range(1, count($plans)), array_values($plans));

        return new self($plans, new Sold($sold), $seniorPrices, $withOptions, $soldOut);
    }

    /**
     * The plans in MirzaBot's own order, each keyed by its place in it (1, 2, …) — not its product's seq, which follows
     * the order the rows were staged in —, after the `$after`th. The staged copy never changes, so a place stays the
     * plan's whenever the import goes on.
     *
     * @return iterable<int, ImportedPlan>
     */
    public function plans(int $after = 0): iterable
    {
        foreach ($this->plans as $place => $plan) {
            if ($place > $after) {
                yield $place => $plan;
            }
        }
    }

    /** How many plans the products make. */
    public function count(): int
    {
        return count($this->plans);
    }

    /**
     * The panels a product is sold on: its panel (`Location`), or every one («/all») but those it is hidden from
     * (`hide_panel`, their names); null for every one. A panel MirzaBot no longer has, or a «فروش دستی» one, is none.
     *
     * @param array<string, ?string> $row
     * @return list<string>|null
     */
    private static function panelKeys(array $row, Panels $panels): ?array
    {
        $hidden = json_decode((string) ($row['hide_panel'] ?? ''), true);
        $hidden = is_array($hidden) ? array_values(array_filter($hidden, 'is_string')) : [];
        $location = Cell::text($row['Location'] ?? null);
        if ($location === null || $location === self::EVERY_PANEL) {
            return $hidden === [] ? null : array_values(array_diff($panels->panels(), $hidden));
        }

        return $panels->isPanel($location) && !in_array($location, $hidden, true) ? [$location] : [];
    }
}
