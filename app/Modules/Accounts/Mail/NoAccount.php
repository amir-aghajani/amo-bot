<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Mail;

use App\Core\Mail\MailBody;
use App\Core\Mail\MailMessage;

/**
 * Sent instead of a reset code to an address without an account: it has none. The request answers the same, in the
 * same time, either way, so only the address's owner learns which.
 */
final class NoAccount
{
    public static function message(string $to, string $shop): MailMessage
    {
        return MailBody::message($to, "بازیابی رمز عبور {$shop}", $shop, 'حسابی با این ایمیل نیست', [
            "کسی برای این ایمیل در {$shop} رمز عبور تازه خواست، ولی این ایمیل حسابی در این فروشگاه ندارد؛ کدی فرستاده نشد.",
            'اگر با تلگرام یا گوگل وارد می‌شوید، از همان راه وارد شوید.',
            'اگر این درخواست کار شما نبود، کاری لازم نیست.',
        ]);
    }
}
