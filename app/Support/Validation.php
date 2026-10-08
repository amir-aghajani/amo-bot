<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\UploadedFileInterface;

/**
 * The validation sentences every form repeats, worded once.
 */
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
     * Why PHP did not take an uploaded file whole — past the server's own limit (php.ini's, which may be below the
     * form's), or cut short —; null for one it took.
     */
    public static function uploadFailure(UploadedFileInterface $file): ?string
    {
        return match ($file->getError()) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'فایل بزرگ‌تر از حد مجاز سرور است.',
            default => 'آپلود فایل ناتمام ماند؛ دوباره تلاش کنید.',
        };
    }
}
