<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Core\Support\Files;

/**
 * The moment an update swaps the app's files and brings its database along (or takes an update back): every request but
 * the one doing it is told to come back in a moment — public/index.php answers it a 503 with Retry-After before the app
 * is even loaded (retryAfter()), since the app's own files are what is being replaced. The flag, storage/updating.flag,
 * holds the time the work last said it is alive (hold(), again before each long part of it); a flag older than SECONDS
 * is ignored, so work that died never keeps the shop shut for good.
 */
final class Maintenance
{
    /** Where the flag is, from the app's folder: public/index.php reads it there, before there is a container. */
    public const FLAG = 'storage/updating.flag';

    /** How long a flag holds the shop after its work last said it is alive: an install's longest part, with room. */
    public const SECONDS = 120;

    /** What a request is told while an update installs — the panel, a customer's website, Telegram's webhook. */
    public const MESSAGE = 'فروشگاه در حال به‌روزرسانی است؛ چند لحظه دیگر دوباره امتحان کنید.';

    public const UNWRITABLE = 'فایل storage/updating.flag نوشته نشد و فروشگاه در حین نصب نگه داشته نمی‌شود؛ اجازه نوشتن در پوشه storage را بررسی کنید.';

    /** The longest a held request is told to wait before it asks again: an install takes seconds. */
    private const RETRY_SECONDS = 10;

    public function __construct(private readonly string $flag) {}

    /**
     * The shop held from now on, the flag's time now.
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

    /**
     * How long a request should wait before it asks again (Retry-After) while the flag at `$flag` holds the shop; null
     * when nothing does — no flag, one whose work died (older than SECONDS), or one that holds no time of the shop's.
     */
    public static function retryAfter(string $flag, int $now): ?int
    {
        $since = trim(Files::read($flag) ?? '');
        $left = preg_match('/^\d{1,12}$/', $since) === 1 ? (int) $since + self::SECONDS - $now : 0;

        return $left > 0 && $left <= self::SECONDS ? min($left, self::RETRY_SECONDS) : null;
    }
}
