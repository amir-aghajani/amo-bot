<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/** Why a service sent by its link was not added to the customer's account (Exceptions\ServiceClaimRefusedException). */
enum ClaimRefusal: string
{
    /** The shop takes no services by their link (ServiceClaimSettings), or is an agent's. */
    case Off = 'off';
    /** What was sent holds no subscription link or config. */
    case NotALink = 'not_a_link';
    /** None of the shop's panels has a client the link is for. */
    case NotFound = 'not_found';
    /** The service is already another customer's in the shop (or in an agent's). */
    case Taken = 'taken';
    /** The service is already the customer's own. */
    case Yours = 'yours';
    /** The panel serves no subscription link for the client, the one thing a customer gets. */
    case NoLink = 'no_link';
    /** A panel that might have it did not answer, and none of the others has it. */
    case Unavailable = 'unavailable';

    public function message(): string
    {
        return match ($this) {
            self::Off => 'افزودن سرویس با لینک فعلا فعال نیست.',
            self::NotALink => 'این لینک سرویس نیست؛ لینک اشتراک (ساب) یا یکی از کانفیگ‌های سرویس را بفرستید.',
            self::NotFound => 'سرویسی با این لینک روی سرورهای فروشگاه پیدا نشد.',
            self::Taken => 'این سرویس در حساب مشتری دیگری است؛ اگر سرویس شماست با پشتیبانی تماس بگیرید.',
            self::Yours => 'این سرویس از قبل در سرویس‌های شماست.',
            self::NoLink => 'برای این سرویس لینک اشتراک ساخته نمی‌شود؛ با پشتیبانی تماس بگیرید.',
            self::Unavailable => 'الان امکان بررسی این لینک نیست؛ کمی بعد دوباره امتحان کنید.',
        };
    }

    /** 422 refused as it stands; 409 another customer holds it; 502 a panel the shop relies on did not answer. */
    public function status(): int
    {
        return match ($this) {
            self::Taken => 409,
            self::Unavailable => 502,
            default => 422,
        };
    }
}
