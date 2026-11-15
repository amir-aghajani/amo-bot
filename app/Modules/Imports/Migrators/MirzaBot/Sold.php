<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\MirzaBot;

/**
 * What a bot of MirzaBot's line sold under which of its plans (its Catalog's): a product, as its discount codes name one,
 * and a service — by its product's name on its panel, as the bot finds a service's product to renew it (a name repeats,
 * a product for each panel), or, under a name the bot no longer sells there (renamed or deleted), by its traffic and
 * days on its panel when they are one plan's alone.
 *
 * @phpstan-type Product array{key: string, name: ?string, volume: string, days: int, panels: list<string>|null, plan: string}
 */
final class Sold
{
    /** @var array<string, string> A product's key (`code_product`) → its plan's key */
    private readonly array $byProduct;

    /** @var array<string, list<Product>> A product's name → the products of that name */
    private readonly array $byName;

    /**
     * @param list<Product> $products Every product — its key, name, traffic, days and the panels it is sold on (null for
     *                                every one) — with its plan's key, the customers' first: a name both carry is the
     *                                customers' plan
     */
    public function __construct(private readonly array $products)
    {
        $byProduct = [];
        $byName = [];
        foreach ($products as $product) {
            $byProduct[$product['key']] ??= $product['plan'];
            if ($product['name'] !== null) {
                $byName[$product['name']][] = $product;
            }
        }
        $this->byProduct = $byProduct;
        $this->byName = $byName;
    }

    /** The plan a product (by `code_product`) was sold as; null for one the bot does not have. */
    public function planOfProduct(string $code): ?string
    {
        return $this->byProduct[$code] ?? null;
    }

    /**
     * The plan a service on the panel `$panel` was bought under: the product of its name (`name_product`) sold there,
     * else the one plan of the products of its traffic and days (`Volume`, `Service_time`) sold there; null for none, or
     * more than one.
     */
    public function planOf(?string $name, ?string $volume, ?string $days, string $panel): ?string
    {
        foreach ($name === null ? [] : $this->byName[$name] ?? [] as $product) {
            if (self::soldOn($product, $panel)) {
                return $product['plan'];
            }
        }
        $volume = Cell::number($volume);
        $plans = [];
        foreach ($this->products as $product) {
            if ($volume !== null && $product['volume'] === $volume && $product['days'] === Cell::whole($days) && self::soldOn($product, $panel)) {
                $plans[$product['plan']] = true;
            }
        }

        return count($plans) === 1 ? (string) array_key_first($plans) : null;
    }

    /** @param Product $product */
    private static function soldOn(array $product, string $panel): bool
    {
        return $product['panels'] === null || in_array($panel, $product['panels'], true);
    }
}
