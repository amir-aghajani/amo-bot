<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\Faoxima;

use App\Modules\Imports\Migrators\MirzaBot\Cell;
use App\Modules\Imports\Migrators\MirzaBot\Sold;
use App\Modules\Imports\Migrators\MirzaBot\Twins;
use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Staging\Staged;

/**
 * Faoxima's products as this shop's plans. Faoxima prices its agents apart as MirzaBot does (Twins): a product only its
 * agents see (`agent` n or n2, never f or all) beside a customers' one of the same traffic, days and panels is that plan's
 * agent price, and an agents' product with no such twin a plan of its own, only for agents.
 */
final class Catalog
{
    /** Who sees a product, as Faoxima's `agent` lists them: customers (`f`), or everyone. */
    private const FOR_CUSTOMERS = ['f', 'all', 'allusers'];

    /**
     * @param array<int, ImportedPlan> $plans By their place in Faoxima's order, from 1
     * @param array<string, list<string>> $byCategory A category's name → its plans' keys
     */
    private function __construct(
        private readonly array $plans,
        /** Which plan a product, or a service, was sold under. */
        public readonly Sold $sold,
        private readonly array $byCategory,
        /** Whether some product had a price only for advanced agents (n2), which no plan keeps apart. */
        public readonly bool $seniorPrices,
        /** How many products had options no plan here keeps: a device limit, one purchase each, traffic reset on a schedule. */
        public readonly int $withOptions,
    ) {}

    /** The staged copy's products, paired and keyed (`code_product`, else the product's id). */
    public static function of(Staged $staged, Panels $panels): self
    {
        /** @var array<int, array{row: array<string, ?string>, key: string, panels: list<string>|null, forCustomers: bool, tier: ?string, twin: string, category: ?string, price: string}> $products */
        $products = [];
        $keys = [];
        foreach ($staged->rows('product') as $seq => $row) {
            $seen = array_map(strtolower(...), Cell::list($row['agent'] ?? null));
            $panelKeys = self::panelKeys($row, $panels);
            $soldOn = $panelKeys ?? ['/all'];
            sort($soldOn);
            // Faoxima's codes are four random hex digits: one that repeats is the product's id instead.
            $code = Cell::text($row['code_product'] ?? null);
            $key = $code !== null && !isset($keys[$code]) ? $code : '#' . (Cell::text($row['id'] ?? null) ?? $seq);
            $keys[$key] = true;
            $products[$seq] = [
                'row' => $row,
                'key' => $key,
                'panels' => $panelKeys,
                'forCustomers' => $seen === [] || array_intersect($seen, self::FOR_CUSTOMERS) !== [],
                'tier' => array_values(array_intersect(Twins::TIERS, $seen))[0] ?? null,
                'twin' => implode('|', [Cell::number($row['Volume_constraint'] ?? null) ?? '0', Cell::whole($row['Service_time'] ?? null), implode(',', $soldOn)]),
                'category' => self::categories($row)[0] ?? null,
                'price' => Cell::amount($row['price_product'] ?? null),
            ];
        }
        [$twinOf, $agentPrices] = Twins::pair($products);

        $plans = [];
        $byCategory = [];
        $sold = [];
        $seniorPrices = false;
        $withOptions = 0;
        // The customers' products first: a name both carry is the customers' plan.
        uasort($products, static fn(array $a, array $b): int => $b['forCustomers'] <=> $a['forCustomers']);
        foreach ($products as $seq => $product) {
            $row = $product['row'];
            $name = Cell::text($row['name_product'] ?? null);
            $traffic = Cell::number($row['Volume_constraint'] ?? null) ?? '0';
            $days = Cell::whole($row['Service_time'] ?? null);
            $planKey = isset($twinOf[$seq]) ? $products[$twinOf[$seq]]['key'] : $product['key'];
            if (!isset($twinOf[$seq])) {
                $plans[$seq] = new ImportedPlan(
                    key: $product['key'],
                    name: $name ?? 'پلن ' . $product['key'],
                    category: $product['category'],
                    price: $product['price'],
                    agentPrice: $agentPrices[$seq]['n'] ?? $agentPrices[$seq]['n2'] ?? null,
                    trafficGb: $traffic,
                    durationDays: max(0, $days),
                    ipLimit: max(0, Cell::whole($row['ip_limit'] ?? null)),
                    note: Cell::text($row['note'] ?? null),
                    panelKeys: $product['panels'],
                    agentsOnly: !$product['forCustomers'],
                );
            }
            foreach (self::categories($row) as $category) {
                $byCategory[$category][] = $planKey;
            }
            $sold[] = ['key' => $product['key'], 'name' => $name, 'volume' => $traffic, 'days' => $days, 'panels' => $product['panels'], 'plan' => $planKey];
            $seniorPrices = $seniorPrices || (!$product['forCustomers'] && $product['tier'] === 'n2');
            $reset = Cell::text($row['data_limit_reset'] ?? null);
            $withOptions += Cell::whole($row['hwid_limit'] ?? null) > 0 || trim((string) ($row['one_buy_status'] ?? '')) === '1' || ($reset !== null && $reset !== 'no_reset') ? 1 : 0;
        }
        // In Faoxima's own order: by position, those without one (0) last, then by id.
        $order = static function (int $seq) use ($products): array {
            $position = Cell::whole($products[$seq]['row']['position'] ?? null);

            return [$position === 0, $position, Cell::whole($products[$seq]['row']['id'] ?? null), $seq];
        };
        uksort($plans, static fn(int $a, int $b): int => $order($a) <=> $order($b));
        $plans = $plans === [] ? [] : array_combine(range(1, count($plans)), array_values($plans));

        return new self($plans, new Sold($sold), array_map(static fn(array $keys): array => array_values(array_unique($keys)), $byCategory), $seniorPrices, $withOptions);
    }

    /**
     * The plans in Faoxima's own order, each keyed by its place in it (1, 2, …) — not its product's seq, which follows
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
     * The plans of the categories named.
     *
     * @param list<string> $categories
     * @return list<string>
     */
    public function plansIn(array $categories): array
    {
        return array_values(array_unique(array_merge(...array_map(fn(string $category): array => $this->byCategory[$category] ?? [], $categories))));
    }

    /**
     * The panels a product is sold on (`Location`: names, or «/all»); null for every one. A panel Faoxima no longer has,
     * or a «فروش دستی» one, is none.
     *
     * @param array<string, ?string> $row
     * @return list<string>|null
     */
    private static function panelKeys(array $row, Panels $panels): ?array
    {
        $names = Cell::list($row['Location'] ?? null);
        if ($names === [] || in_array('/all', $names, true)) {
            return null;
        }

        return array_values(array_filter($names, $panels->isPanel(...)));
    }

    /**
     * A product's categories by name (`category`, a list), the first the one it shows under.
     *
     * @param array<string, ?string> $row
     * @return list<string>
     */
    private static function categories(array $row): array
    {
        return Cell::list($row['category'] ?? null);
    }
}
