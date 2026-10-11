<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Mail;

use App\Core\Mail\MailBody;
use App\Core\Mail\MailMessage;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Support\Persian;

/**
 * The code that finishes a sign-up, emailed to the address it proves. It holds nothing the requester typed: a sign-up
 * may name anyone's address, so typed words would let anyone send their text there.
 */
final class SignUpCode
{
    public static function message(string $to, string $shop, string $code): MailMessage
    {
        return MailBody::message($to, "کد تایید ثبت‌نام در {$shop}", $shop, 'تایید ثبت‌نام', [
            "برای تمام کردن ثبت‌نام در {$shop}، این کد را در وب‌سایت وارد کنید:",
        ], $code, [
            'این کد تا ' . Persian::digits((string) intdiv(AuthChallenges::CODE_SECONDS, 60)) . ' دقیقه دیگر کار می‌کند.',
            'اگر شما در این فروشگاه ثبت‌نام نکرده‌اید، این ایمیل را نادیده بگیرید؛ بدون این کد حسابی ساخته نمی‌شود.',
        ]);
    }
}
