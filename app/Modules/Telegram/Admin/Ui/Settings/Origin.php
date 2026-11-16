<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui\Settings;

use App\Modules\Telegram\Admin\Ui\Admin;

/**
 * Where a screen was opened from, when that is a menu other than its own parent — as 0.10.0's «🤖 تنظیمات ربات» opened
 * the cards that live with their features (the discount codes' settings, the channels): a window of then still carries
 * it (`show` with `from.<menu>`). The screen keeps it in its state while its own actions run, and its «⬅️ بازگشت» goes
 * back there (back()). Opened without one — from its own list, its hub — it forgets it.
 */
final class Origin
{
    /** What an origin starts with among show()'s arguments. */
    private const MARK = 'from.';

    /** The state key it is kept under, in the screen's own state. */
    private const KEY = '_from';

    /** A screen's id an origin may be. */
    private const SCREEN = '/^[A-Za-z][A-Za-z0-9_-]*$/';

    /**
     * Take what show() was opened with: an origin kept, or — opened without one — the one kept forgotten.
     *
     * @param list<string> $args
     */
    public static function opened(Admin $admin, string $screen, array $args): void
    {
        $first = $args[0] ?? '';
        $origin = str_starts_with($first, self::MARK) ? substr($first, strlen(self::MARK)) : '';
        $admin->remember($screen, [self::KEY => preg_match(self::SCREEN, $origin) === 1 ? $origin : null]);
    }

    /** Where «⬅️ بازگشت» goes from `$screen`: its origin, else `$parent`. */
    public static function back(Admin $admin, string $screen, string $parent): string
    {
        $origin = $admin->state($screen)[self::KEY] ?? null;

        return is_string($origin) && preg_match(self::SCREEN, $origin) === 1 ? $origin : $parent;
    }
}
