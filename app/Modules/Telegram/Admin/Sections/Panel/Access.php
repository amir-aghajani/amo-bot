<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel;

use App\Modules\Telegram\Admin\AdminSeat;

/**
 * Who opens which of the panel settings' screens, as the routes are mounted (routes/api.php): config.php, the schedule
 * and the imports are the owner's in the main shop (`$main`); the update and the webhooks the owner's in any shop
 * (`$system`); the website, the mini app and the backups every seat, the owner's and an agent's (`$configuration`).
 */
final class Access
{
    /** config.php's groups, the schedule, the imports: the owner, working the main shop. */
    public static function main(AdminSeat $seat): bool
    {
        return $seat->isOwner() && $seat->shop()->isMain();
    }

    /**
     * config.php's database: the owner themselves, working the main shop — never one of its admins with the owner's seat
     * (ConfigSettings refuses them its save and its test).
     */
    public static function theOwner(AdminSeat $seat): bool
    {
        return self::main($seat) && $seat->principal->isTheOwner();
    }

    /** The update, the webhooks, the system's state: the owner. */
    public static function system(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }
}
