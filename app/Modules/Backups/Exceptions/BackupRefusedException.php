<?php

declare(strict_types=1);

namespace App\Modules\Backups\Exceptions;

use App\Core\Exceptions\ValidationException;

/** The refusals of a backup file the shop will not read or restore, in the admin's words, each a 422 under `file`. */
final class BackupRefusedException
{
    public const NOT_A_BACKUP = 'این فایل، فایل پشتیبان AmoBot نیست.';
    public const BROKEN = 'این فایل پشتیبان ناقص است یا تغییر کرده، یا در نصب دیگری از AmoBot ساخته شده است؛ فقط فایلی که همین فروشگاه ساخته و دست نخورده بازگردانده می‌شود.';
    public const NEWER = 'این پشتیبان با AmoBot %s ساخته شده که از نسخه این فروشگاه (%s) تازه‌تر است؛ اول فروشگاه را به‌روز کنید.';
    public const OLDER = 'این پشتیبان با AmoBot %s ساخته شده و نسخه این فروشگاه (%s) نمی‌تواند آن را بازگرداند؛ فقط پشتیبانی بازگردانده می‌شود که با AmoBot %s یا تازه‌تر ساخته شده باشد.';
    public const WHOLE_ONLY = 'این پشتیبان کل نصب است و فقط از پنل مالک، در فروشگاه اصلی، بازگردانده می‌شود.';
    public const SHOP_ONLY = 'این پشتیبان یک فروشگاه است، نه کل نصب؛ آن را در پنل همان فروشگاه بازگردانید.';
    public const OTHER_SHOP = 'این پشتیبان فروشگاه دیگری است (%s)؛ هر فروشگاه فقط پشتیبان خودش را بازمی‌گرداند.';
    public const STRAY_ROWS = 'این پشتیبان ردیف‌هایی دارد که از آن این فروشگاه نیستند؛ بازگردانده نمی‌شود.';
    public const GONE_SERVERS = 'این پشتیبان %s سرویس روی سرورهایی دارد که دیگر نیستند (%s)؛ بازگردانی آن‌ها را بی‌سرور می‌گذاشت.';
    public const EXPIRED = 'این فایل دیگر آماده بازگردانی نیست؛ آن را دوباره بارگذاری کنید.';
    public const NO_FILE = 'فایل پشتیبان را انتخاب کنید.';
    public const TOO_LARGE = 'فایل بزرگ‌تر از چیزی است که هاست می‌پذیرد (%s)؛ حد بارگذاری را در تنظیمات PHP هاست بالا ببرید.';

    public static function because(string $message): ValidationException
    {
        return ValidationException::on('file', $message);
    }
}
