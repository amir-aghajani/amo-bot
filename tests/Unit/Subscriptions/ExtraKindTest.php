<?php

declare(strict_types=1);

namespace Tests\Unit\Subscriptions;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Support\Traffic;
use Tests\TestCase;

/**
 * The three extras a service may buy and the orders that buy them: each kind its own order type and back, named and
 * counted as the customer reads it, bought for themselves and for a service they have.
 */
final class ExtraKindTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app(); // the order's casts
    }

    public function testEachKindIsBoughtByAnOrderOfItsOwnAndNamedAsItsOrderIs(): void
    {
        foreach (ExtraKind::cases() as $kind) {
            self::assertSame($kind, ExtraKind::of($kind->orderType()), $kind->value);
            self::assertTrue($kind->orderType()->forThemselves(), "{$kind->value}: discounts, loyalty, cashback and the draw count it");
            self::assertTrue($kind->orderType()->forService(), "{$kind->value}: for a service the customer has");
            self::assertSame(3, $kind->quantityOf((new Order())->forceFill($kind->columns(3))), "{$kind->value}: kept on the order as it is read back");
        }
        self::assertNull(ExtraKind::of(OrderType::Renewal), 'a renewal is no extra');
        self::assertSame([OrderType::ExtraTraffic, OrderType::ExtraTime, OrderType::ExtraDevices], array_map(static fn(ExtraKind $kind): OrderType => $kind->orderType(), ExtraKind::cases()));
        self::assertSame('خرید، تمدید، حجم اضافه، زمان اضافه یا کاربر اضافه', OrderType::forThemselvesInWords(), 'what a discount or a cashback rule may be for, as its refusal lists it');
    }

    public function testDevicesAreCountedInUsersUnderTheirOwnField(): void
    {
        self::assertSame(['کاربر اضافه', 'کاربر', 'devices', '۲ کاربر'], [ExtraKind::Devices->label(), ExtraKind::Devices->unit(), ExtraKind::Devices->field(), ExtraKind::Devices->words(2)]);
        self::assertSame([ExtraKind::Devices, 'extra_devices'], [ExtraKind::tryFrom('devices'), OrderType::ExtraDevices->value]);

        $order = (new Order())->forceFill(['type' => OrderType::ExtraDevices, 'devices' => 3, 'traffic_bytes' => null, 'days' => null]);
        self::assertSame([3, 0, 0], [ExtraKind::Devices->quantityOf($order), ExtraKind::Traffic->quantityOf($order), ExtraKind::Time->quantityOf($order)], 'how many it buys, under its own column');
        self::assertSame(10, ExtraKind::Traffic->quantityOf((new Order())->forceFill(['traffic_bytes' => 10 * Traffic::GIGABYTE])));
    }
}
