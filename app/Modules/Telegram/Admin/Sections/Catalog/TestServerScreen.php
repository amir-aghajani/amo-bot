<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * A server's free test accounts (the owner's: the server page's «اکانت تست» card, GET/PUT /servers/{id}/test-account),
 * saved apart from its connection: whether it gives them, a test's traffic and hours, and whether the hours count from
 * the first connection. Opened from the tests' servers list, or from the server's own page (`v`), and back to where it
 * was opened. The server is the chat's (`id`, set by `srv`): a settings card's buttons carry a field's place alone.
 *
 * Actions: `srv` (server id, `v` when opened from the server's page: opens its card), and the card's own.
 */
final class TestServerScreen extends SettingsScreen
{
    public const ID = 'tsrv';

    /** The server's page (the servers section's), the card opened from it goes back to. */
    private const SERVER = 'svv';

    /** The `srv` argument of a card opened from the server's page. */
    public const FROM_SERVER = 'v';

    /** The server's name, for the card's title: read with its id. */
    private string $name = '';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $this->name = (string) ($admin->state(self::ID)['name'] ?? '');

        return parent::show($admin, $args);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'srv') {
            $id = self::id($args);
            $names = array_column(TestServersScreen::servers($admin), 'name', 'id');
            $admin->remember(self::ID, ['id' => $id, 'name' => (string) ($names[$id] ?? ''), 'from' => ($args[1] ?? '') === self::FROM_SERVER ? self::SERVER : '']);

            return $this->show($admin, []);
        }
        $this->name = (string) ($admin->state(self::ID)['name'] ?? '');

        return parent::act($admin, $action, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $this->name = (string) ($admin->state(self::ID)['name'] ?? '');

        return parent::input($admin, $action, $args, $answer);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🧪 اکانت تست' . ($this->name !== '' ? ' «' . $this->name . '»' : ' سرور');
    }

    protected function path(): array
    {
        return $this->fromServer() ? ['مدیریت', 'سرورها', $this->name] : ['مدیریت', 'فروشگاه', 'اکانت تست', 'سرورها'];
    }

    protected function parent(): string
    {
        return $this->fromServer() ? self::SERVER : TestServersScreen::ID;
    }

    protected function intro(): string
    {
        return 'اکانت تست رایگانی که مشتری‌ها روی این سرور می‌گیرند، در همه فروشگاه‌ها از جمله نماینده‌ها. اکانت روی همه اینباندهایی که این سرور می‌فروشد ساخته می‌شود؛ اکانت‌هایی که قبلا داده شده‌اند شرایط خودشان را نگه می‌دارند.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('test_enabled', 'اکانت تست روی این سرور', 'روشن که باشد، مشتری‌های هر فروشگاهی که اکانت تست را در «اکانت تست › تنظیمات» خودش روشن کرده، روی این سرور اکانت تست می‌گیرند.'),
            FieldDef::number('test_traffic_mb', 'حجم هر اکانت تست', 'مگابایت', 'بین ۱ تا ۱۰۲۴۰۰ مگابایت.'),
            FieldDef::number('test_duration_hours', 'مدت هر اکانت تست', 'ساعت', 'بین ۱ تا ۷۲۰ ساعت.'),
            FieldDef::toggle('test_on_hold', 'شروع از اولین اتصال', 'روشن: ساعت‌ها از اولین اتصال مشتری شمرده می‌شوند. خاموش: از همان لحظه که اکانت تست ساخته می‌شود.'),
        ];
    }

    protected function source(): SettingsSource
    {
        return new ServerTestSource(self::ID);
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        if ($admin->opens(TestSettingsScreen::ID)) {
            $view->row(Button::to('⚙️ اکانت تست › تنظیمات', TestSettingsScreen::ID));
        }
    }

    /** Whether the card was opened from the server's page (and goes back to it). */
    private function fromServer(): bool
    {
        return ($this->here?->state(self::ID)['from'] ?? '') === self::SERVER;
    }
}
