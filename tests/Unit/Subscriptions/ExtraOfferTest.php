<?php

declare(strict_types=1);

namespace Tests\Unit\Subscriptions;

use App\Modules\Subscriptions\DTO\ExtraOffer;
use PHPUnit\Framework\TestCase;

/**
 * What an extra costs by a price list: an amount offered at its own price or the unit price, any amount typed between
 * the least and the most while a unit price prices it — and nothing else. Devices' prices are prorated to the days a
 * service has left.
 */
final class ExtraOfferTest extends TestCase
{
    public function testAnAmountOfferedCostsItsOwnPriceElseTheUnitPriceAndAnyOtherIsTypedWithinItsBounds(): void
    {
        $offer = new ExtraOffer('3000.00', [10 => '25000.00', 20 => null], 1, 50);

        self::assertSame('25000.00', $offer->priceOf(10), 'its own price: cheaper than ten units');
        self::assertSame('60000.00', $offer->priceOf(20), 'without one, the unit price twenty times');
        self::assertSame('21000.00', $offer->priceOf(7), 'typed');
        self::assertSame('150000.00', $offer->priceOf(50), 'the most typed');
        self::assertNull($offer->priceOf(51));
        self::assertNull($offer->priceOf(0));
        self::assertTrue($offer->typed());
        self::assertTrue($offer->sells());
        self::assertSame([['quantity' => 10, 'price' => '25000.00'], ['quantity' => 20, 'price' => '60000.00']], $offer->choices());
    }

    public function testWithoutAUnitPriceOnlyTheAmountsWithAPriceOfTheirOwnSell(): void
    {
        $offer = new ExtraOffer(null, [7 => '30000.00', 30 => null], 1, 30);

        self::assertFalse($offer->typed(), 'nothing prices a typed amount');
        self::assertSame('30000.00', $offer->priceOf(7));
        self::assertNull($offer->priceOf(30), 'an amount without a price of its own and no unit price: not sold');
        self::assertNull($offer->priceOf(5));
        self::assertSame([['quantity' => 7, 'price' => '30000.00']], $offer->choices());

        self::assertFalse((new ExtraOffer(null, [30 => null], 1, 30))->sells(), 'nothing priced, nothing sold');
        self::assertFalse((new ExtraOffer(null, [], 1, 30))->sells());
        self::assertTrue((new ExtraOffer('1000.00', [], 1, 30))->sells(), 'a unit price alone sells what is typed');
        self::assertFalse((new ExtraOffer('1000.00', [], 40, 30))->sells(), 'a least above the most leaves nothing to type');
    }

    public function testHeldToAPoolTheAmountsAboveItGoAndSoDoesTheRoomToTypeAboveIt(): void
    {
        $offer = (new ExtraOffer('3000.00', [10 => null, 50 => '120000.00'], 5, 100))->upTo(30);

        self::assertSame([10], array_keys($offer->options));
        self::assertSame([5, 30], [$offer->min, $offer->max]);
        self::assertSame('90000.00', $offer->priceOf(30));
        self::assertNull($offer->priceOf(31));
        self::assertNull($offer->priceOf(50));

        $short = (new ExtraOffer('3000.00', [10 => null], 5, 100))->upTo(3);
        self::assertFalse($short->sells(), 'less in the pool than the least typed and every amount offered: nothing');
    }

    public function testProratedEveryPriceIsItsShareOfThirtyDaysRoundedUpOnceForTheWholeAmount(): void
    {
        $set = new ExtraOffer('1000.00', [2 => '1500.00', 3 => null], 1, 5);
        $offer = $set->prorated(7);

        self::assertSame([7, null], [$offer->days, $set->days], 'a copy: the list as set stays');
        self::assertSame('350.00', $offer->priceOf(2), 'its own 1,500 for 7 of 30 days');
        self::assertSame('700.00', $offer->priceOf(3), 'the unit price three times, then its share');
        self::assertSame(['234.00', '934.00'], [$offer->priceOf(1), $offer->priceOf(4)], '233.33 and 933.33, each rounded up once');
        self::assertSame('234.00', $offer->unitPrice());
        self::assertSame('1000.00', $set->unitPrice(), 'at the price set');
        self::assertSame([['quantity' => 2, 'price' => '350.00'], ['quantity' => 3, 'price' => '700.00']], $offer->choices());
        self::assertSame([[2], 7], [array_keys($offer->upTo(2)->options), $offer->upTo(2)->days], 'held to a pool, still prorated');
        self::assertSame('2000.00', $set->prorated(60)->priceOf(1), 'sixty days: twice the price');
    }
}
