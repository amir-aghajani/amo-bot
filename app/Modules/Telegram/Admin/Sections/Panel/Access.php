<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel;

use App\Modules\Telegram\Admin\AdminSeat;

/**
 * Who opens which of the panel settings' screens, as the routes are mounted (routes/api.php): config.php, the schedule
 * and the imports are the owner's in the main shop (`$main`); the update and the webhooks the owner's in any shop
 * (`$system`); the website, the mini app and the backups every seat that sets the shop up (`$configuration`).
 */
final class Access
{
    /** config.php's groups, the schedule, the imports: the owner, working the main shop. */
    public static function main(AdminSeat $seat): bool
    {
        return $seat->isOwner() && $seat->shop()->isMain();
    }

    /** The update, the webhooks, the system's state: the owner. */
    public static function system(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }
}
