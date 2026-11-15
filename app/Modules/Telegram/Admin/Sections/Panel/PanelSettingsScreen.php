<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Panel\Backups\BackupsScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Config\AdvancedScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Config\AppScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Config\DatabaseScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Config\MailScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Config\SmsScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Config\TelegramScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Imports\ImportsScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Schedule\ScheduleScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🧰 تنظیمات پنل»: the panel's own settings, its sections in its order — config.php's groups (the file and whether it
 * can be written said first), the login, the update, the schedule, the backups, the imports from another bot, the
 * advanced ones and how the bots get their messages —, each the seat may open: the owner in the main shop all, in an
 * agent's shop the update, the webhooks and that shop's backups, an agent their shop's backups. The panel's «ظاهر» is
 * this browser's look, which a chat has none of: said in one line.
 */
final class PanelSettingsScreen extends BaseScreen
{
    public const ID = 'pp';

    /** The sections, in the panel's order: [screen, label]. */
    private const SECTIONS = [
        [TelegramScreen::ID, '🤖 ربات تلگرام'],
        [DatabaseScreen::ID, '🗄 دیتابیس'],
        [AppScreen::ID, '🏷 برنامه'],
        [MailScreen::ID, '📧 ایمیل'],
        [SmsScreen::ID, '📨 پیامک'],
        [LoginScreen::ID, '🔐 ورود به پنل'],
        [UpdateScreen::ID, '⬆️ به‌روزرسانی'],
        [ScheduleScreen::ID, '⏱ زمان‌بندی کارها'],
        [BackupsScreen::ID, '💾 پشتیبان‌گیری'],
        [ImportsScreen::ID, '🔀 انتقال از ربات دیگر'],
        [AdvancedScreen::ID, '🛠 پیشرفته'],
        [SystemScreen::ID, '📡 دریافت پیام‌ها'],
    ];

    public function __construct(private readonly Screens $screens) {}

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $view = View::make('🧰 تنظیمات پنل', 'مدیریت', 'تنظیمات');
        if (Access::main($admin->seat)) {
            $file = (array) ($admin->api->get('/settings/config')['file'] ?? []);
            $writable = ($file['writable'] ?? true) === true;
            $view->line("تنظیمات برنامه، ربات، دیتابیس، ایمیل، پیامک و ورود به پنل. این مقادیر در فایل \u{2068}config.php\u{2069} برنامه ذخیره می‌شوند، نه در دیتابیس؛ تا وقتی دیتابیس هم خراب باشد، ویرایش‌پذیر می‌مانند.")
                ->line('📄 ' . Fmt::ltr((string) ($file['path'] ?? 'config.php')) . ' · ' . ($writable ? 'قابل ویرایش' : 'فقط خواندنی'));
            if (!$writable) {
                $view->line("⚠️ <b>فایل \u{2068}config.php\u{2069} قابل نوشتن نیست.</b> در \u{2068}File Manager\u{2069} هاست به این فایل و پوشه برنامه اجازه نوشتن بدهید تا تنظیمات از این‌جا ذخیره شوند؛ تا آن موقع می‌توانید خود فایل را همان‌جا ویرایش کنید.");
            }
        } elseif ($admin->seat->isOwner()) {
            $view->line('تنظیمات خود پنل (فایل config.php، زمان‌بندی کارها، انتقال از ربات دیگر) مال فروشگاه اصلی است؛ برای آن‌ها فروشگاه اصلی را انتخاب کنید. این‌جا: به‌روزرسانی، دریافت پیام‌ها و پشتیبان همین فروشگاه.');
        } else {
            $view->line('پشتیبان فروشگاه شما؛ بقیه تنظیمات پنل با مالک فروشگاه است.');
        }
        $view->line()->line('<i>🎨 «ظاهر» پنل (روشن یا تیره) فقط به مرورگر مربوط است؛ این‌جا همان تم تلگرام خودتان است.</i>');

        return $view->grid(2, ...$this->screens->buttons($admin->seat, self::SECTIONS))->back('hc');
    }
}
