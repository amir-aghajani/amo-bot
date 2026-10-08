<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Mail;

use App\Core\Mail\MailBody;
use App\Core\Mail\MailMessage;

/**
 * What a forgotten password of an address without an account sends it, instead of a code: that it has none — so the
 * answer, and how long it takes, is the same whether or not the address has an account, and only its owner learns which.
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
