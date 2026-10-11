<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Support\Persian;
use App\Support\Traffic;

/**
 * An extra a customer may buy for a service («حجم و زمان اضافه», Services\CustomerExtras): traffic in GB, or time in days.
 */
enum ExtraKind: string
{
    case Traffic = 'traffic';
    case Time = 'time';

    /** «حجم اضافه», «زمان اضافه». */
    public function label(): string
    {
        return match ($this) {
            self::Traffic => 'حجم اضافه',
            self::Time => 'زمان اضافه',
        };
    }

    /** What it is counted in. */
    public function unit(): string
    {
        return match ($this) {
            self::Traffic => 'گیگابایت',
            self::Time => 'روز',
        };
    }

    /** The website's request field for how much is bought (`gb`, `days`), where its refusals go. */
    public function field(): string
    {
        return match ($this) {
            self::Traffic => 'gb',
            self::Time => 'days',
        };
    }

    /** `$quantity` of it in words: «۱۰ گیگابایت», «۷ روز». */
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
        };
    }

    /** How much of it the order buys: GB, or days. */
    public function quantityOf(Order $order): int
    {
        return match ($this) {
            self::Traffic => intdiv((int) $order->traffic_bytes, Traffic::GIGABYTE),
            self::Time => (int) $order->days,
        };
    }

    /** The extra an order of `$type` buys; null for any other order. */
    public static function of(OrderType $type): ?self
    {
        return match ($type) {
            OrderType::ExtraTraffic => self::Traffic,
            OrderType::ExtraTime => self::Time,
            default => null,
        };
    }
}
