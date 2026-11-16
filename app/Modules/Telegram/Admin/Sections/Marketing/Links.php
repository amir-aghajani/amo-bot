<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\Keyboards\KeyboardScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Nav;

/**
 * Buttons to other sections' screens — a customer's page, an order, a service, the keyboards —, offered only where the
 * screen is built and the seat may open it (the panel's links to its other pages).
 */
final class Links
{
    /** A customer's page (Customers), an order (Sales), a service (Sales), the bot's keyboards (Bot). */
    public const CUSTOMER = 'usv';
    public const ORDER = 'orv';
    public const SERVICE = 'sbv';
    public const KEYBOARDS = 'kb';

    /** A button to `$screen`'s `$action`; null when there is no such screen, or the seat may not open it. */
    public static function to(Admin $admin, string $label, string $screen, string $action = Nav::SHOW, int|string ...$args): ?Button
    {
        return $admin->opens($screen) ? Button::to($label, $screen, $action, ...$args) : null;
    }

    /**
     * The panel's warning over a program on whose menu button the bot has none (MenuButtonNotice: a menu saved before the
     * button existed): its words (plain text, no mark) and the way to the keyboards; nothing while the program is off or
     * the button is there.
     *
     * @return array{0: string, 1: ?Button}|null
     * @throws ApiRefusal
     */
    public static function menuNotice(Admin $admin, bool $on, string $action, string $label, string $screen): ?array
    {
        if (!$on || KeyboardScreen::hasButton($admin, $action)) {
            return null;
        }

        return ['دکمه «' . $label . '» در منوی ربات نیست، پس مشتری‌ها به ' . $screen . ' راهی ندارند.', self::to($admin, Icons::KEYBOARDS . ' افزودن دکمه در کیبوردها', self::KEYBOARDS)?->primary()];
    }
}
