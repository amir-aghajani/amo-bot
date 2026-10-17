<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Support\Persian;
use App\Support\Traffic;
use App\Support\Unit;

/**
 * An extra a customer may buy for a service («حجم، زمان و کاربر اضافه», Services\CustomerExtras): traffic in GB, time
 * in days, or devices — more of the service's IP limit, priced for 30 days and prorated to what the service has left.
 */
enum ExtraKind: string
{
    case Traffic = 'traffic';
    case Time = 'time';
    case Devices = 'devices';

    /** «حجم اضافه», «زمان اضافه», «کاربر اضافه»: its order's name. */
    public function label(): string
    {
        return $this->orderType()->label();
    }

    /** What it is counted in. */
    public function unit(): string
    {
        return match ($this) {
            self::Traffic => 'گیگابایت',
            self::Time => 'روز',
            self::Devices => 'کاربر',
        };
    }

    /** The unit an amount of it is typed in: the words the bot reads after the number (Input::amountOf()). */
    public function typedUnit(): Unit
    {
        return match ($this) {
            self::Traffic => Unit::Gigabyte,
            self::Time => Unit::Day,
            self::Devices => Unit::Device,
        };
    }

    /** The website's request field for how much is bought (`gb`, `days`, `devices`), where its refusals go. */
    public function field(): string
    {
        return match ($this) {
            self::Traffic => 'gb',
            self::Time => 'days',
            self::Devices => 'devices',
        };
    }

    /** `$quantity` of it in words: «۱۰ گیگابایت», «۷ روز», «۲ کاربر». */
    public function words(int $quantity): string
    {
        return Persian::number($quantity) . ' ' . $this->unit();
    }

    /** The order that buys it. */
    public function orderType(): OrderType
    {
        return match ($this) {
            self::Traffic => OrderType::ExtraTraffic,
            self::Time => OrderType::ExtraTime,
            self::Devices => OrderType::ExtraDevices,
        };
    }

    /**
     * The order's column that holds `$quantity` of it, as it keeps it (quantityOf() reads it back).
     *
     * @return array{traffic_bytes: int}|array{days: int}|array{devices: int}
     */
    public function columns(int $quantity): array
    {
        return match ($this) {
            self::Traffic => ['traffic_bytes' => Traffic::bytesOfGb($quantity)],
            self::Time => ['days' => $quantity],
            self::Devices => ['devices' => $quantity],
        };
    }

    /** How much of it the order buys: GB, days, or devices. */
    public function quantityOf(Order $order): int
    {
        return match ($this) {
            self::Traffic => intdiv((int) $order->traffic_bytes, Traffic::GIGABYTE),
            self::Time => (int) $order->days,
            self::Devices => (int) $order->devices,
        };
    }

    /** The extra an order of `$type` buys; null for any other order. */
    public static function of(OrderType $type): ?self
    {
        return match ($type) {
            OrderType::ExtraTraffic => self::Traffic,
            OrderType::ExtraTime => self::Time,
            OrderType::ExtraDevices => self::Devices,
            default => null,
        };
    }
}
