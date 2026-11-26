<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Core\OwnerAccountsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «ورود به پنل» (the owner's, in the main shop): the panel's login is changed from the panel alone — proving it with the
 * current password signs every other browser out, which a chat cannot stand in for —, said; and, to the owner themselves,
 * the Telegram accounts that are the owner here, the section's other card, are «👑 حساب تلگرام مالک», a tap away.
 */
final class LoginScreen extends BaseScreen
{
    public const ID = 'ppl';

    public const TITLE = '🔐 ورود به پنل';

    public function allows(AdminSeat $seat): bool
    {
        return Access::main($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $view = View::make(self::TITLE, ...PanelSettingsScreen::under())
            ->line("🧰 نام کاربری و رمز عبور پنل در \u{2068}config.php\u{2069} نگه داشته می‌شوند و فقط از خود پنل عوض می‌شوند: «تنظیمات پنل › ورود به پنل». با ذخیره آن‌جا، مرورگرها و دستگاه‌های دیگری که وارد پنل شده‌اند خارج می‌شوند.");
        // Which Telegram accounts are the owner is the owner's own (OwnerAccountsScreen): not offered to an admin.
        if ($admin->seat->principal->isTheOwner()) {
            $view->note('حساب‌های تلگرامی که در ربات اصلی مالک‌اند و از همین‌جا مدیریت می‌کنند: «' . Icons::OWNER . ' حساب تلگرام مالک».')
                ->row(Button::to(Icons::OWNER . ' حساب تلگرام مالک', OwnerAccountsScreen::ID)->primary());
        }

        return $view->back(PanelSettingsScreen::ID);
    }
}
