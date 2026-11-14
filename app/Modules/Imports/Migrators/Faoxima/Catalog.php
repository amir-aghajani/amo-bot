<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\Faoxima;

use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Staging\Staged;

/**
 * Faoxima's products as this shop's plans. Faoxima prices its agents apart: a product only its agents see (`agent` n or
 * n2, never f or all) beside a customers' one of the same traffic, days and panels is that plan's agent price — the
 * plain agents' («نماینده عادی», n) over the advanced ones' (n2) — and what was sold under it was sold under that plan.
 * An agents' product with no such twin is a plan of its own, only for agents.
 */
final class Catalog
{
    /** Who sees a product, as Faoxima's `agent` lists them: customers (`f`), or everyone. */
    private const FOR_CUSTOMERS = ['f', 'all', 'allusers'];

    /** Agents' tiers, the one whose price a plan keeps first. */
    private const TIERS = ['n', 'n2'];

    /**
     * @param array<int, ImportedPlan> $plans By their place in Faoxima's order, from 1
     * @param array<string, string> $byProduct A product's key (`code_product`) → its plan's key
     * @param array<string, string> $byName A product's name → its plan's key
     * @param array<string, list<string>> $byCategory A category's name → its plans' keys
     * @param list<array{volume: string, days: int, panels: list<string>|null, plan: string}> $sold Every product's traffic, days and panels, with its plan's key
     */
    private function __construct(
        private readonly array $plans,
        private readonly array $byProduct,
        private readonly array $byName,
        private readonly array $byCategory,
        private readonly array $sold,
        /** Whether some product had a price only for advanced agents (n2), which no plan keeps apart. */
        public readonly bool $seniorPrices,
        /** How many products had options no plan here keeps: a device limit, one purchase each, traffic reset on a schedule. */
        public readonly int $withOptions,
    ) {}

    /** The staged copy's products, paired and keyed (`code_product`, else the product's id). */
    public static function of(Staged $staged, Panels $panels): self
    {
        /** @var array<int, array{row: array<string, ?string>, key: string, forCustomers: bool, tier: ?string, twin: string}> $products */
        $products = [];
        $keys = [];
        foreach ($staged->rows('product') as $seq => $row) {
            $seen = array_map(strtolower(...), Cell::list($row['agent'] ?? null));
            $sold = self::panelKeys($row, $panels) ?? ['/all'];
            sort($sold);
            // Faoxima's codes are four random hex digits: one that repeats is the product's id instead.
            $code = Cell::text($row['code_product'] ?? null);
            $key = $code !== null && !isset($keys[$code]) ? $code : '#' . (Cell::text($row['id'] ?? null) ?? $seq);
            $keys[$key] = true;
            $products[$seq] = [
                'row' => $row,
                'key' => $key,
                'forCustomers' => $seen === [] || array_intersect($seen, self::FOR_CUSTOMERS) !== [],
                'tier' => array_values(array_intersect(self::TIERS, $seen))[0] ?? null,
                'twin' => implode('|', [Cell::number($row['Volume_constraint'] ?? null) ?? '0', Cell::whole($row['Service_time'] ?? null), implode(',', $sold)]),
            ];
        }

        // Each agents' product of a tier takes the first customers' twin no product of that tier took yet — of its own
        // category when there is one —, in Faoxima's order.
        $twinOf = [];
        $agentPrices = [];
        foreach (self::TIERS as $tier) {
            foreach ($products as $seq => $product) {
                if ($product['forCustomers'] || $product['tier'] !== $tier) {
                    continue;
                }
                $twins = array_filter($products, static fn(array $other, int $at): bool => $other['forCustomers'] && $other['twin'] === $product['twin'] && !isset($agentPrices[$at][$tier]), ARRAY_FILTER_USE_BOTH);
                $category = self::categories($product['row'])[0] ?? null;
                $twin = array_key_first(array_filter($twins, static fn(array $other): bool => (self::categories($other['row'])[0] ?? null) === $category)) ?? array_key_first($twins);
                if ($twin !== null) {
                    $twinOf[$seq] = $twin;
                    $agentPrices[$twin][$tier] = Cell::amount($product['row']['price_product'] ?? null);
                }
            }
        }

        $plans = [];
        $byProduct = [];
        $byName = [];
        $byCategory = [];
        $sold = [];
        $seniorPrices = false;
        $withOptions = 0;
        // The customers' products first: a name both carry is the customers' plan.
        uasort($products, static fn(array $a, array $b): int => $b['forCustomers'] <=> $a['forCustomers']);
        foreach ($products as $seq => $product) {
            $row = $product['row'];
            $planKey = isset($twinOf[$seq]) ? $products[$twinOf[$seq]]['key'] : $product['key'];
            if (!isset($twinOf[$seq])) {
                $plans[$seq] = new ImportedPlan(
                    key: $product['key'],
                    name: Cell::text($row['name_product'] ?? null) ?? 'پلن ' . $product['key'],
                    category: self::categories($row)[0] ?? null,
                    price: Cell::amount($row['price_product'] ?? null),
                    agentPrice: $agentPrices[$seq]['n'] ?? $agentPrices[$seq]['n2'] ?? null,
                    trafficGb: Cell::number($row['Volume_constraint'] ?? null) ?? '0',
                    durationDays: max(0, Cell::whole($row['Service_time'] ?? null)),
                    ipLimit: max(0, Cell::whole($row['ip_limit'] ?? null)),
                    note: Cell::text($row['note'] ?? null),
                    panelKeys: self::panelKeys($row, $panels),
                    agentsOnly: !$product['forCustomers'],
                );
            }
            $byProduct[$product['key']] ??= $planKey;
            $name = Cell::text($row['name_product'] ?? null);
            if ($name !== null) {
                $byName[$name] ??= $planKey;
            }
            foreach (self::categories($row) as $category) {
                $byCategory[$category][] = $planKey;
            }
            $sold[] = ['volume' => Cell::number($row['Volume_constraint'] ?? null) ?? '0', 'days' => Cell::whole($row['Service_time'] ?? null), 'panels' => self::panelKeys($row, $panels), 'plan' => $planKey];
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

        return new self($plans, $byProduct, $byName, array_map(static fn(array $keys): array => array_values(array_unique($keys)), $byCategory), $sold, $seniorPrices, $withOptions);
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

    /** The plan a product (by `code_product`) was sold as; null for one Faoxima does not have. */
    public function planOfProduct(string $code): ?string
    {
        return $this->byProduct[$code] ?? null;
    }

    /** The plan a service bought under the product named `$name` was bought from; null for none so named. */
    public function planNamed(?string $name): ?string
    {
        return $name === null ? null : $this->byName[$name] ?? null;
    }

    /**
     * The plan a service whose product Faoxima no longer has by its name (renamed or deleted) was bought under: the one
     * plan of the products of its traffic and days (`Volume`, `Service_time`) sold on its panel; null when no plan is,
     * or more than one.
     */
    public function planLike(?string $volume, ?string $days, string $panel): ?string
    {
        $volume = Cell::number($volume);
        $plans = [];
        foreach ($this->sold as $product) {
            if ($volume !== null && $product['volume'] === $volume && $product['days'] === Cell::whole($days) && ($product['panels'] === null || in_array($panel, $product['panels'], true))) {
                $plans[$product['plan']] = true;
            }
        }

        return count($plans) === 1 ? (string) array_key_first($plans) : null;
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
