<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Mail;

use App\Core\Mail\MailBody;
use App\Core\Mail\MailMessage;

/**
 * Tells an address taken off an account that it no longer signs in to it — sent there because the account's notices no
 * longer reach it, and the change may not have been its owner's.
 */
final class EmailRemoved
{
    public static function message(string $to, string $shop): MailMessage
    {
        return MailBody::message($to, "ایمیل از حساب شما در {$shop} برداشته شد", $shop, 'ایمیل از حساب برداشته شد', [
            "این ایمیل همین حالا از حساب شما در {$shop} برداشته شد و دیگر راه ورود به آن حساب نیست؛ رمز عبور و ورود دو مرحله‌ای آن هم با آن رفت.",
            'اگر این کار شما نبود، هر چه زودتر با پشتیبانی فروشگاه در تماس باشید.',
        ]);
    }
}
