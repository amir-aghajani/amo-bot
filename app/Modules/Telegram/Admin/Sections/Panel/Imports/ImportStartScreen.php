<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Imports;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «انتقال تازه»: which bot the data is of — each bot AmoBot reads, with what comes over and its notes —, then where it is
 * read from: the bot's backup file, sent here, or its database, connected to (ImportDatabaseScreen). Either starts the
 * import's reading, which ImportsScreen then goes on with.
 *
 * Actions: `m` (n: migrator n picked), `up` (asks for the file).
 */
final class ImportStartScreen extends BaseScreen
{
    public const ID = 'ppis';

    public function allows(AdminSeat $seat): bool
    {
        return Access::main($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $page = $admin->api->get('/imports');
        $migrators = DraftForm::drivers(Json::rows($page, 'migrators'));
        $picked = self::picked($admin, $migrators);
        $view = View::make('➕ انتقال تازه', 'مدیریت', 'تنظیمات پنل', 'انتقال از ربات دیگر')
            ->line('داده‌های ربات قبلی یک بار خوانده می‌شود و پیش از آن‌که چیزی در فروشگاه نوشته شود، همه را می‌بینید و انتخاب می‌کنید چه چیزهایی منتقل شود. سرویس‌ها همان‌طور که روی پنل هستند می‌آیند: همان لینک، همان حجم مصرف‌شده و همان تاریخ پایان.');
        if ($migrators === []) {
            return $view->line()->line('این نسخه AmoBot هنوز داده‌های هیچ ربات دیگری را نمی‌خواند.')->back(ImportsScreen::ID);
        }

        $view->line()->line('<b>ربات قبلی</b>:');
        $buttons = [];
        foreach (array_keys($migrators) as $n => $key) {
            $buttons[] = Button::to(($key === $picked ? '● ' : '') . $migrators[$key]['label'], self::ID, 'm', $n);
        }
        $view->grid(2, ...$buttons);
        $migrator = $migrators[$picked];
        $view->line('<b>' . Fmt::ltr($migrator['label']) . '</b>: ' . Fmt::e($migrator['description']));
        foreach ($migrator['notes'] as $note) {
            $view->line('• ' . Fmt::e($note));
        }
        if (is_string($migrator['traits']['vendor'] ?? null)) {
            $view->row(Button::url('🔗 ' . preg_replace('#^https?://#', '', $migrator['traits']['vendor']), $migrator['traits']['vendor']));
        }

        $limit = (int) ($page['upload_max_bytes'] ?? 0);
        $view->line()->line('<b>📤 فایل پشتیبان</b>: یکی از این‌ها را همین‌جا بفرستید:');
        if (isset(Words::BACKUP_FROM[$picked])) {
            $view->line('• ' . Words::BACKUP_FROM[$picked]);
        }
        $view->line("• خروجی دیتابیس ربات از \u{2068}phpMyAdmin\u{2069} هاستش (\u{2068}Export\u{2069} با فرمت \u{2068}SQL\u{2069}) یا فایل \u{2068}mysqldump\u{2069}")
            ->line("<i>فایل \u{2068}.zip\u{2069}، \u{2068}.sql\u{2069} یا \u{2068}.gz\u{2069} باشد؛ تلگرام فایل بزرگ‌تر از ۲۰ مگابایت را به ربات نمی‌دهد" . ($limit > 0 ? ' (پنل تا ' . Fmt::bytes($limit) . ' می‌پذیرد)' : '') . '. پس از پایان انتقال، فایل و هرچه از آن خوانده شده پاک می‌شود.</i>')
            ->line('<i>' . Words::FINAL_BACKUP . '</i>')
            ->line()->line('<b>🔌 اتصال به دیتابیس</b>: دیتابیس ربات قبلی فقط خوانده می‌شود و چیزی در آن نوشته نمی‌شود.');

        return $view->row(Button::to('📤 فرستادن فایل پشتیبان', self::ID, 'up'), Button::to('🔌 اتصال به دیتابیس', ImportDatabaseScreen::ID))
            ->back(ImportsScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'm':
                $migrators = Words::migrators($admin);
                $key = array_keys($migrators)[self::id($args)] ?? null;
                if ($key !== null) {
                    $admin->remember(self::ID, ['m' => $key]);
                }
                break;
            case 'up':
                return $admin->ask(new Prompt('فایل پشتیبان ربات قبلی', self::ID, 'up', [], "فایل \u{2068}.zip\u{2069}، \u{2068}.sql\u{2069} یا \u{2068}.gz\u{2069} را به صورت فایل همین‌جا بفرستید؛ تا ۲۰ مگابایت. خواندنش چند لحظه طول می‌کشد.", file: true));
        }

        return $this->show($admin, []);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action !== 'up') {
            return $this->show($admin, []);
        }
        $file = $answer->document() ?? throw new ApiRefusal(422, 'فایلی نرسید؛ پشتیبان ربات قبلی را به صورت فایل بفرستید. تلگرام فایل بزرگ‌تر از ۲۰ مگابایت را به ربات نمی‌دهد؛ فایل بزرگ‌تر را zip کنید یا از پنل بفرستید.');
        $migrators = Words::migrators($admin);
        $admin->api->upload('/imports', 'file', $file['name'], $file['bytes'], $file['mime'], ['migrator' => self::picked($admin, $migrators)]);
        $admin->notice('✅ فایل رسید؛ خواندنش را با «▶️ ادامه» پیش ببرید.');

        return $admin->open(ImportsScreen::ID);
    }

    /**
     * The bot picked: the chat's pick, else the first.
     *
     * @param array<string, mixed> $migrators
     */
    public static function picked(Admin $admin, array $migrators): string
    {
        $kept = $admin->state(self::ID)['m'] ?? null;

        return is_string($kept) && isset($migrators[$kept]) ? $kept : (string) array_key_first($migrators);
    }
}
