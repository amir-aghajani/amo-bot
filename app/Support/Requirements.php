<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Application;
use App\Core\Config\ConfigFile;
use App\Core\Database\DatabaseManager;

/**
 * What a host must have before the shop runs: the one list the web installer shows — each with its name (`ext-curl`,
 * its key in the list) and its label in the installer's words —, so it forgets no extension composer.json requires
 * (bcmath for money, fileinfo for the receipts' content type) nor the one the database driver needs. PHP's version is
 * not among them: Composer's platform check stops an older PHP before any of this runs. Last, what the shop runs without
 * (`optional`) — the extensions the owner's panel updates the shop with (composer.json's `suggest`): shown, holding
 * nothing up.
 */
final class Requirements
{
    public function __construct(
        private readonly Application $app,
        private readonly DatabaseManager $database,
        private readonly ConfigFile $file,
    ) {}

    /** @return list<array{name: string, label: string, ok: bool, optional: bool}> In the order the installer lists them */
    public function check(): array
    {
        // The driver DB_CONNECTION names — MySQL's until the installer writes another.
        $driver = $this->database->driver();
        $requirements = [];
        foreach ($driver->extensions() as $extension) {
            $requirements[] = self::requirement("ext-{$extension}", "افزونه {$extension}، برای اتصال به {$driver->describe()->label}", extension_loaded($extension));
        }

        return [
            ...$requirements,
            self::requirement('ext-curl', 'افزونه curl، برای ارتباط با تلگرام و پنل‌ها', extension_loaded('curl')),
            self::requirement('ext-mbstring', 'افزونه mbstring، برای متن فارسی', extension_loaded('mbstring')),
            self::requirement('ext-openssl', 'افزونه openssl، برای رمزنگاری', extension_loaded('openssl')),
            self::requirement('ext-bcmath', 'افزونه bcmath، برای حساب مبلغ‌ها', extension_loaded('bcmath')),
            self::requirement('ext-fileinfo', 'افزونه fileinfo، برای تشخیص نوع فایل', extension_loaded('fileinfo')),
            // A request needs a few tens of MB; drawing a QR card on its picture, some more.
            self::requirement('memory_limit >= 64M', 'حافظه PHP (memory_limit) دست‌کم 64M', self::memoryLimit() === -1 || self::memoryLimit() >= 64 * 1024 * 1024),
            self::requirement('storage/ writable', 'اجازه نوشتن در پوشه storage', is_writable($this->app->storagePath())),
            // The installer, the settings screen and the panel's login write the shop's configuration.
            self::requirement('config.php writable', 'اجازه نوشتن فایل config.php (و پوشه برنامه، که فایل در آن است)', $this->file->isWritable()),
            // The panel's own update: a new release's signature checked, its zip unpacked (Modules\Updates).
            self::requirement('ext-sodium', 'افزونه sodium، برای بررسی امضای نسخه‌های تازه (به‌روزرسانی از داخل پنل)', extension_loaded('sodium'), optional: true),
            self::requirement('ext-zip', 'افزونه zip، برای باز کردن نسخه‌های تازه (به‌روزرسانی از داخل پنل)', extension_loaded('zip'), optional: true),
        ];
    }

    /**
     * Whether the host has what the shop runs on: every requirement of `$checks` (check()'s) met but the optional ones.
     *
     * @param list<array{name: string, label: string, ok: bool, optional: bool}> $checks
     */
    public static function met(array $checks): bool
    {
        return array_filter($checks, static fn(array $requirement): bool => !$requirement['ok'] && !$requirement['optional']) === [];
    }

    /** @return array{name: string, label: string, ok: bool, optional: bool} */
    private static function requirement(string $name, string $label, bool $ok, bool $optional = false): array
    {
        return ['name' => $name, 'label' => $label, 'ok' => $ok, 'optional' => $optional];
    }

    /** PHP's memory_limit in bytes; -1 for none — what a picture decoded whole may take (Users\Services\CustomerPictures). */
    public static function memoryLimit(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return -1;
        }
        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
