<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/** Why a customer cannot take a free test account now (Exceptions\TestAccountRefusedException). */
enum TestRefusal: string
{
    /** Test accounts are switched off in the shop (TestAccountSettings). */
    case Off = 'off';
    /** Only customers who never bought a service with a price may take one, and they have. */
    case NewcomersOnly = 'newcomers_only';
    /** They took as many as their quota allows (for good, or within its window). */
    case Used = 'used';
    /** The shop gave as many as its cap allows today. */
    case DailyLimit = 'daily_limit';
    /** No server gives a test now (none switched on for tests, none can take a client, or an agent's pool is short). */
    case NoServer = 'no_server';
    /** The agent's shop sells nothing new now, free tests neither (Bot::sells()). */
    case Suspended = 'suspended';

    public function message(): string
    {
        return match ($this) {
            self::Off => 'اکانت تست فعلا فعال نیست.',
            self::NewcomersOnly => 'اکانت تست ویژه کاربرانی است که هنوز سرویسی نخریده‌اند.',
            self::Used => 'سهمیه اکانت تست شما تمام شده است.',
            self::DailyLimit => 'سقف اکانت‌های تست امروز پر شده است.',
            self::NoServer => 'فعلا سروری برای اکانت تست در دسترس نیست.',
            self::Suspended => 'فعلا اکانت تست داده نمی‌شود.',
        };
    }
}
