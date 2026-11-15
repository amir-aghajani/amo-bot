<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\MirzaBot;

use App\Modules\Imports\Migrators\MirzaBot\Twins;
use PHPUnit\Framework\TestCase;

/** How a bot of MirzaBot's line pairs its agents' products with the customers' ones of the same traffic, days and panels. */
final class TwinsTest extends TestCase
{
    public function testEachLevelTakesTheFirstTwinItHasNotTakenOfItsOwnCategoryFirst(): void
    {
        $product = static fn(bool $forCustomers, ?string $tier, string $twin, ?string $category, string $price): array => ['forCustomers' => $forCustomers, 'tier' => $tier, 'twin' => $twin, 'category' => $category, 'price' => $price];

        [$twinOf, $agentPrices] = Twins::pair([
            1 => $product(true, null, '30|30|/all', 'الف', '100.00'),
            2 => $product(true, null, '30|30|/all', 'ب', '110.00'),
            3 => $product(false, 'n2', '30|30|/all', 'ب', '80.00'),
            4 => $product(false, 'n', '30|30|/all', 'ب', '90.00'),
            5 => $product(false, 'n', '30|30|/all', null, '95.00'),
            6 => $product(false, 'n', '30|30|/all', 'ب', '99.00'),
            7 => $product(false, 'n', '30|30|هلند', 'الف', '50.00'),
        ]);

        self::assertSame([4 => 2, 5 => 1, 3 => 2], $twinOf, 'the plain agents\' first; one with every twin taken, or none of its traffic, days and panels, has none');
        self::assertSame([2 => ['n' => '90.00', 'n2' => '80.00'], 1 => ['n' => '95.00']], $agentPrices, 'each level a price of its own on the same twin');
    }

    public function testCustomersProductsPairWithNothing(): void
    {
        $customers = ['forCustomers' => true, 'tier' => null, 'twin' => '10|30|/all', 'category' => null, 'price' => '1000.00'];

        self::assertSame([[], []], Twins::pair([1 => $customers, 2 => $customers]));
    }
}
