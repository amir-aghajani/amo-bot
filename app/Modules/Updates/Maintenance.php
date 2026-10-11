<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Core\Support\Files;

/**
 * Holds the shop while an update swaps its files: public/index.php answers every other request a 503 with Retry-After
 * before loading the app (whose files are being replaced). The flag (storage/updating.flag) holds the time the work last
 * said it is alive; one older than SECONDS is ignored, so a crashed update never keeps the shop shut.
 */
final class Maintenance
{
    /** The flag's path from the app's folder; public/index.php reads it before there is a container. */
    public const FLAG = 'storage/updating.flag';

    /** How long a flag holds after its last refresh: an install's longest part, with room. */
    public const SECONDS = 120;

    /** The answer every request gets while an update installs. */
    public const MESSAGE = 'فروشگاه در حال به‌روزرسانی است؛ چند لحظه دیگر دوباره امتحان کنید.';

    public const UNWRITABLE = 'فایل storage/updating.flag نوشته نشد و فروشگاه در حین نصب نگه داشته نمی‌شود؛ اجازه نوشتن در پوشه storage را بررسی کنید.';

    /** The longest a held request is told to wait before it asks again: an install takes seconds. */
    private const RETRY_SECONDS = 10;

    public function __construct(private readonly string $flag) {}

    /**
     * Hold the shop, or refresh the hold.
     *
     * @throws \RuntimeException when the flag cannot be written
     */
    public function hold(): void
    {
        Files::writeAtomically($this->flag, (string) time());
    }

    /** The shop open again. */
    public function release(): void
    {
        Files::delete($this->flag);
    }

    /** The Retry-After seconds while the flag at `$flag` holds the shop; null when none, stale or unreadable. */
    public static function retryAfter(string $flag, int $now): ?int
    {
        $since = trim(Files::read($flag) ?? '');
        $left = preg_match('/^\d{1,12}$/', $since) === 1 ? (int) $since + self::SECONDS - $now : 0;

        return $left > 0 && $left <= self::SECONDS ? min($left, self::RETRY_SECONDS) : null;
    }
}
