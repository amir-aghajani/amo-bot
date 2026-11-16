<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Imports;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Panel\Access;
use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftForm;
use App\Modules\Telegram\Admin\Sections\Panel\Forms\DraftScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «اتصال به دیتابیس» of a new import: the old bot's MySQL database — its host, port, name, user and password (typed,
 * taken away once read, kept encrypted until sent) —, connected to read-only: tried at once, each refusal under the field
 * at fault, and its reading begun.
 */
final class ImportDatabaseScreen extends DraftScreen
{
    public const ID = 'ppid';

    public function allows(AdminSeat $seat): bool
    {
        return Access::main($seat);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🔌 اتصال به دیتابیس ربات قبلی';
    }

    protected function path(): array
    {
        return ImportsScreen::under();
    }

    protected function parent(): string
    {
        return ImportStartScreen::ID;
    }

    protected function submitLabel(): string
    {
        return '🔌 اتصال و خواندن';
    }

    protected function alwaysSubmits(): bool
    {
        return true;
    }

    protected function done(): string
    {
        return '✅ وصل شد؛ خواندن داده‌ها را با «▶️ ادامه» پیش ببرید.';
    }

    protected function form(Admin $admin): DraftForm
    {
        return DraftForm::fixed([
            DraftForm::field('host', 'text', 'هاست', "مثلا \u{2068}localhost\u{2069} یا آدرس سرور دیتابیس", required: true, ltr: true),
            DraftForm::field('port', 'number', 'پورت', required: true, default: '3306'),
            DraftForm::field('database', 'text', 'نام دیتابیس', required: true, ltr: true),
            DraftForm::field('username', 'text', 'نام کاربری', required: true, ltr: true),
            DraftForm::field('password', 'secret', 'رمز عبور', transient: true),
        ], []);
    }

    protected function submit(Admin $admin, array $payload): void
    {
        $migrators = Words::migrators($admin);
        $port = (string) ($payload['port'] ?? '');
        $admin->api->post('/imports/database', [
            'migrator' => ImportStartScreen::picked($admin, $migrators),
            'host' => (string) ($payload['host'] ?? ''),
            'port' => ctype_digit($port) ? (int) $port : $port,
            'database' => (string) ($payload['database'] ?? ''),
            'username' => (string) ($payload['username'] ?? ''),
            'password' => (string) ($payload['password'] ?? ''),
        ]);
    }

    protected function saved(Admin $admin): View
    {
        return $admin->open(ImportsScreen::ID);
    }

    protected function intro(Admin $admin, DraftForm $form, string $driver): array
    {
        $migrators = Words::migrators($admin);
        $picked = ImportStartScreen::picked($admin, $migrators);

        return [
            'ربات قبلی: ' . Fmt::ltr($migrators[$picked]['label'] ?? $picked),
            "دیتابیس فقط خوانده می‌شود و چیزی در آن نوشته نمی‌شود. اگر دیتابیس ربات قبلی روی هاست دیگری است، در \u{2068}cPanel\u{2069} همان هاست اتصال از بیرون (\u{2068}Remote MySQL\u{2069}) را برای این سرور باز کنید. برای انتقال نهایی، اول ربات قبلی را خاموش کنید و بعد به دیتابیسش وصل شوید؛ آنچه بعد از خوانده شدن در ربات قبلی عوض شود منتقل نمی‌شود.",
        ];
    }
}
