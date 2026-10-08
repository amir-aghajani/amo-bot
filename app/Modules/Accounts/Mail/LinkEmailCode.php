<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Mail;

use App\Core\Mail\MailBody;
use App\Core\Mail\MailMessage;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Support\Persian;

/** The code a customer adds this address to their account with — from the website, signed in — emailed to the address it proves. */
final class LinkEmailCode
{
    public static function message(string $to, string $shop, string $code): MailMessage
    {
        return MailBody::message($to, "کد تایید ایمیل حساب {$shop}", $shop, 'افزودن ایمیل به حساب', [
            "برای افزودن این ایمیل به حساب‌تان در {$shop} و ورود با آن، این کد را در وب‌سایت وارد کنید:",
        ], $code, [
            'این کد تا ' . Persian::digits((string) intdiv(AuthChallenges::CODE_SECONDS, 60)) . ' دقیقه دیگر کار می‌کند.',
            'اگر شما این ایمیل را به حسابی اضافه نمی‌کنید، این ایمیل را نادیده بگیرید؛ بدون این کد چیزی تغییر نمی‌کند.',
        ]);
    }
}
