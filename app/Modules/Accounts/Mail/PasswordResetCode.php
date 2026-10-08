<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Mail;

use App\Core\Mail\MailBody;
use App\Core\Mail\MailMessage;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Support\Persian;

/** The code a customer sets a new password with, emailed to their account's address. */
final class PasswordResetCode
{
    public static function message(string $to, string $shop, string $code): MailMessage
    {
        return MailBody::message($to, "کد بازیابی رمز عبور {$shop}", $shop, 'رمز عبور تازه', [
            "برای گذاشتن رمز عبور تازه برای حساب‌تان در {$shop}، این کد را در وب‌سایت وارد کنید:",
        ], $code, [
            'این کد تا ' . Persian::digits((string) intdiv(AuthChallenges::CODE_SECONDS, 60)) . ' دقیقه دیگر کار می‌کند. با رمز تازه، از همه دستگاه‌هایی که با این حساب وارد شده‌اند خارج می‌شوید.',
            'اگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید؛ رمز عبور شما تغییری نمی‌کند.',
        ]);
    }
}
