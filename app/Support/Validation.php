<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\UploadedFileInterface;

/** The validation sentences every form repeats, worded once. */
final class Validation
{
    /** A settings switch that did not arrive as on/off. */
    public const NOT_A_SWITCH = 'مقدار این گزینه باید روشن یا خاموش باشد.';

    /** «نام حداکثر 60 کاراکتر است.» — the limit keeps Latin digits, like every numeric guidance. */
    public static function tooLong(string $label, int $max): string
    {
        return "{$label} حداکثر {$max} کاراکتر است.";
    }

    /**
     * «سقف روزانه را به تومان و بدون اعشار بنویسید؛ خالی یعنی بدون سقف.» — money typed that is no whole Toman; `$blank`
     * says what leaving it blank means, where it may be.
     */
    public static function notWholeToman(string $label, ?string $blank = null): string
    {
        return "{$label} را به تومان و بدون اعشار بنویسید" . ($blank === null ? '.' : "؛ خالی یعنی {$blank}.");
    }

    /** Why PHP did not take an uploaded file whole (past php.ini's limit, or cut short); null when it did. */
    public static function uploadFailure(UploadedFileInterface $file): ?string
    {
        return match ($file->getError()) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'فایل بزرگ‌تر از حد مجاز سرور است.',
            default => 'آپلود فایل ناتمام ماند؛ دوباره تلاش کنید.',
        };
    }
}
