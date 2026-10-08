<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Mail;

use App\Core\Mail\MailBody;
use App\Core\Mail\MailMessage;

/**
 * What a sign-up of an address that has an account sends it, instead of a code: that it has one, and the way back in —
 * so the sign-up's answer is the same whether or not the address has an account, and only its owner learns which.
 */
final class AlreadyRegistered
{
    public static function message(string $to, string $shop): MailMessage
    {
        return MailBody::message($to, "حساب شما در {$shop}", $shop, 'شما از قبل حساب دارید', [
            "کسی با این ایمیل در {$shop} ثبت‌نام کرد، ولی این ایمیل از قبل حسابی در این فروشگاه دارد؛ حساب دیگری ساخته نشد.",
            'برای ورود، از همان راهی وارد شوید که قبلا وارد شده‌اید. اگر رمز عبور ندارید یا آن را فراموش کرده‌اید، در صفحه ورود وب‌سایت «فراموشی رمز عبور» را بزنید.',
            'اگر این ثبت‌نام کار شما نبود، کاری لازم نیست: حساب شما تغییری نکرده است.',
        ]);
    }
}
