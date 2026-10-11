<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Enums;

/** Why the customer cannot spin the lucky wheel now (Exceptions\WheelRefusedException). */
enum WheelRefusal: string
{
    /** The wheel is switched off (WheelSettings). */
    case Off = 'off';
    /** Only customers who have bought a service may spin, and they have not. */
    case BuyersOnly = 'buyers_only';
    /** Their last spin was given less than the cooldown ago. */
    case TooSoon = 'too_soon';
    /** No enabled slice can reach them now (no slices, or every one needs a running service they lack). */
    case Empty = 'empty';
    /** Their wallet cannot pay the spin. */
    case Short = 'short';

    public function message(): string
    {
        return match ($this) {
            self::Off => 'گردونه شانس فعلا فعال نیست.',
            self::BuyersOnly => 'گردونه شانس ویژه مشتری‌هایی است که دست‌کم یک سرویس خریده‌اند.',
            self::TooSoon => 'هنوز نوبت چرخش بعدی شما نرسیده است.',
            self::Empty => 'گردونه شانس فعلا جایزه‌ای برای شما ندارد.',
            self::Short => 'موجودی کیف پول برای چرخش کافی نیست.',
        };
    }
}
