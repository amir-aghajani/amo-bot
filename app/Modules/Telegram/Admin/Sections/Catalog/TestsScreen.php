<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\Keyboards\KeyboardScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🧪 اکانت تست» (the panel's «اکانت تست» page, the owner's and an agent's): the tests customers took, newest first —
 * searched by customer, narrowed by their state —, each opening its own screen (TestScreen); the page's other sections,
 * every server's test terms (TestServersScreen) and the rules (TestSettingsScreen), a tap away; and what the panel warns
 * of above them: the tests switched off, no server giving one now, the menu without the «اکانت تست» button. Opened with
 * `user` (a customer's id: `Nav::to('ts', 'user', $id)`), it lists that customer's tests alone, as the panel's `?user=`.
 *
 * Actions: the list's, and `user` (customer id; 0 for every customer).
 */
final class TestsScreen extends ListScreen
{
    public const ID = 'ts';

    /** Each state as the panel words it (TEST_STATE), with its emoji. */
    public const STATES = [
        'making' => '⏳ در حال ساخت',
        'waiting' => '🕓 در انتظار اولین اتصال',
        'running' => '🟢 فعال',
        'disabled' => '⏸ غیرفعال',
        'ended' => '⌛️ تمام‌شده',
        'removed' => '🗑 حذف‌شده',
    ];

    /** The customer the list is narrowed to (0: every one), read from the chat's state with each showing. */
    private int $user = 0;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $this->user = (int) ($admin->state(self::ID)['user'] ?? 0);

        return parent::show($admin, $args);
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action === 'user') {
            $admin->remember(self::ID, ['user' => self::id($args), 'page' => 1]);
        }

        return $this->show($admin, []);
    }

    protected function fixed(): array
    {
        return $this->user > 0 ? ['user' => $this->user] : [];
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🧪 اکانت تست';
    }

    protected function path(): array
    {
        return ['مدیریت', 'فروشگاه'];
    }

    protected function endpoint(): string
    {
        return '/tests';
    }

    protected function key(): string
    {
        return 'tests';
    }

    protected function filters(): array
    {
        return ['status' => [self::ANY => 'همه'] + self::STATES];
    }

    protected function searchHint(): string
    {
        return 'نام، نام کاربری یا شناسه تلگرام مشتری';
    }

    protected function empty(): string
    {
        return 'هنوز کسی اکانت تست نگرفته است؛ هر بار که مشتری‌ای در ربات اکانت تست بگیرد، این‌جا می‌آید.';
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function parent(): string
    {
        return 'hs';
    }

    protected function summary(array $meta): array
    {
        return [
            'هر اکانت تستی که مشتری‌ها گرفته‌اند، تازه‌ترین اول: حجم و مدتش، وضعیتش و این‌که مشتری بعد از آن خرید کرد یا نه.',
            ...($this->user > 0 ? ['👤 فقط اکانت‌های تست مشتری ' . Fmt::id($this->user)] : []),
        ];
    }

    protected function item(array $row): Button
    {
        $state = self::STATES[(string) ($row['state'] ?? '')] ?? '';
        $customer = Json::part($row, 'customer');
        $server = is_array($row['server'] ?? null) ? (string) ($row['server']['name'] ?? '') : 'سرور حذف شده';

        return Button::to(
            explode(' ', $state)[0] . ' ' . Fmt::cut((string) ($customer['name'] ?? ''), 18) . ' · ' . Fmt::cut($server, 14) . ((bool) ($row['bought_after'] ?? false) ? ' · 🛒' : ''),
            TestScreen::ID,
            'show',
            (int) ($row['id'] ?? 0),
        );
    }

    protected function extra(View $view, Admin $admin): void
    {
        $settings = self::settings($admin);
        $servers = TestServersScreen::servers($admin);
        if (($settings['test_enabled'] ?? null) === false) {
            $view->line()->line('⚠️ اکانت تست خاموش است: دکمه‌اش در منوی ربات نیست و کسی اکانت تست تازه نمی‌گیرد.');
        } elseif ($servers !== [] && array_filter($servers, static fn(array $server): bool => (bool) ($server['available'] ?? false)) === []) {
            $view->line()->line('⚠️ الان هیچ سروری اکانت تست نمی‌دهد، پس مشتری‌ها اکانت تست نمی‌گیرند.');
        }
        if (($settings['test_enabled'] ?? null) === true && !self::onMenu($admin)) {
            $view->line()->line('⚠️ دکمه «اکانت تست» در منوی ربات نیست، پس مشتری‌ها به گرفتن اکانت تست در ربات راهی ندارند.');
            $view->row(Button::to('⌨️ افزودن دکمه در کیبوردها', 'kb'));
        }
        if ($this->user > 0) {
            $view->row(Button::to('✖️ همه مشتری‌ها', self::ID, 'user', 0));
        }
        $view->row(Button::to('🖥 سرورها', TestServersScreen::ID), Button::to('⚙️ تنظیمات', TestSettingsScreen::ID));
    }

    /**
     * The shop's bot settings (the tests' switch); none when the panel would not say.
     *
     * @return array<string, mixed>
     */
    private static function settings(Admin $admin): array
    {
        try {
            return BotGroupSource::settings($admin);
        } catch (ApiRefusal) {
            return [];
        }
    }

    /** Whether the bot's main menu has the «اکانت تست» button (the panel's MenuButtonNotice); yes when it would not say. */
    private static function onMenu(Admin $admin): bool
    {
        try {
            return KeyboardScreen::hasButton($admin, 'test');
        } catch (ApiRefusal) {
            return true;
        }
    }
}
