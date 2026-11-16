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
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🧰 تنظیمات پنل»: the panel's own settings, its sections in its order two a row — config.php's groups (the file and
 * whether it can be written said first, as a summary), the login, the update, the schedule, the backups, the imports
 * from another bot, the advanced ones and how the bots get their messages —, each the seat may open: the owner in the
 * main shop all, in an agent's shop the update, the webhooks and that shop's backups, an agent their shop's backups. The
 * panel's «ظاهر» is this browser's look, which a chat has none of: said in the folded help.
 */
final class PanelSettingsScreen extends BaseScreen
{
    public const ID = 'pp';

    public const TITLE = Icons::PANEL . ' تنظیمات پنل';

    /** The sections, in the panel's order: [screen, label]. */
    private const SECTIONS = [
        [TelegramScreen::ID, TelegramScreen::TITLE],
        [DatabaseScreen::ID, DatabaseScreen::TITLE],
        [AppScreen::ID, AppScreen::TITLE],
        [MailScreen::ID, '📧 ایمیل'],
        [SmsScreen::ID, '📨 پیامک'],
        [LoginScreen::ID, LoginScreen::TITLE],
        [UpdateScreen::ID, UpdateScreen::TITLE],
        [ScheduleScreen::ID, ScheduleScreen::TITLE],
        [BackupsScreen::ID, BackupsScreen::TITLE],
        [ImportsScreen::ID, ImportsScreen::TITLE],
        [AdvancedScreen::ID, AdvancedScreen::TITLE],
        [SystemScreen::ID, Icons::SYSTEM . ' دریافت پیام‌ها'],
    ];

    public function __construct(private readonly Screens $screens) {}

    /**
     * The path of a screen under it — «مدیریت › تنظیمات › تنظیمات پنل» and the sections `$more` between.
     *
     * @return list<string>
     */
    public static function under(string ...$more): array
    {
        return [Words::ROOT, Fmt::bare(Words::SETTINGS), Fmt::bare(self::TITLE), ...array_values($more)];
    }

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $view = View::make(self::TITLE, Words::ROOT, Fmt::bare(Words::SETTINGS));
        $help = '«ظاهر» پنل (روشن یا تیره) فقط به مرورگر مربوط است؛ این‌جا همان تم تلگرام خودتان است.';
        if (Access::main($admin->seat)) {
            $file = (array) ($admin->api->get('/settings/config')['file'] ?? []);
            $writable = ($file['writable'] ?? true) === true;
            if (!$writable) {
                $view->line("⚠️ <b>فایل \u{2068}config.php\u{2069} قابل نوشتن نیست.</b> در \u{2068}File Manager\u{2069} هاست به این فایل و پوشه برنامه اجازه نوشتن بدهید تا تنظیمات از این‌جا ذخیره شوند؛ تا آن موقع می‌توانید خود فایل را همان‌جا ویرایش کنید.");
            }
            $view->card(['فایل تنظیمات' => Fmt::code((string) ($file['path'] ?? 'config.php')), 'وضعیت' => $writable ? 'قابل ویرایش' : 'فقط خواندنی']);
            $help = "تنظیمات برنامه، ربات، دیتابیس، ایمیل، پیامک و ورود به پنل در فایل \u{2068}config.php\u{2069} برنامه ذخیره می‌شوند، نه در دیتابیس؛ تا وقتی دیتابیس هم خراب باشد، ویرایش‌پذیر می‌مانند. " . $help;
        } elseif ($admin->seat->isOwner()) {
            $view->line("تنظیمات خود پنل (فایل \u{2068}config.php\u{2069}، زمان‌بندی کارها، انتقال از ربات دیگر) مال فروشگاه اصلی است؛ برای آن‌ها فروشگاه اصلی را انتخاب کنید.");
        } else {
            $view->line('پشتیبان فروشگاه شما؛ بقیه تنظیمات پنل با مالک فروشگاه است.');
        }

        return $view->grid(2, ...$this->screens->buttons($admin->seat, self::SECTIONS))->help($help)->back('hc');
    }
}
