<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\Keyboards\KeyboardScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Keyboards\KeyboardsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🧪 اکانت تست» (the panel's «اکانت تست» page, the owner's and an agent's): the tests customers took, newest first —
 * searched by customer, narrowed by their state —, each a button that is its row (its state, customer, server, and 🛒
 * once the customer bought after it) to its own screen (TestScreen); what the panel warns of over them — the tests
 * switched off (with the switch a tap away), no server giving one now, the menu without the «اکانت تست» button —; and
 * the page's other sections, the rules (TestSettingsScreen) and every server's test terms (TestServersScreen). Opened with
 * `user` (a customer's id: `Nav::to('ts', 'user', $id)`), it lists that customer's tests alone, as the panel's `?user=`.
 *
 * Actions: the list's, and `user` (customer id; 0 for every customer).
 */
final class TestsScreen extends ListScreen
{
    public const ID = 'ts';

    public const TITLE = Icons::TESTS . ' اکانت تست';

    /** Each state as the panel words it (TEST_STATE), with its emoji. */
    public const STATES = [
        'making' => '⏳ در حال ساخت',
        'waiting' => '🕓 در انتظار اولین اتصال',
        'running' => Words::ACTIVE,
        'disabled' => Words::INACTIVE,
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
        return self::TITLE;
    }

    protected function path(): array
    {
        return CatalogFmt::under();
    }

    protected function endpoint(): string
    {
        return '/tests';
    }

    protected function key(): string
    {
        return 'tests';
    }

    protected function noun(): string
    {
        return 'اکانت تست';
    }

    protected function filters(): array
    {
        return ['status' => [self::ANY => 'همه'] + self::STATES];
    }

    protected function narrowedBy(): array
    {
        return $this->user > 0 ? ['👤 مشتری ' . Fmt::plain(Fmt::id($this->user))] : [];
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
        return CatalogFmt::HUB;
    }

    /** What the panel warns of over the list: the tests off, no server giving one, the menu without their button. */
    protected function summary(array $meta): array
    {
        $admin = $this->here;
        if ($admin === null) {
            return [];
        }
        $enabled = self::settings($admin)['test_enabled'] ?? null;
        $servers = TestServersScreen::servers($admin);
        $off = $enabled === false;
        $none = !$off && $servers !== [] && array_filter($servers, static fn(array $server): bool => (bool) ($server['available'] ?? false)) === [];
        $hidden = $enabled === true && !self::onMenu($admin);
        // The ways to fix them are extra()'s buttons: what was found is kept for it.
        $admin->remember(self::ID, ['off' => $off, 'menu' => $hidden]);

        return array_values(array_filter([
            $off ? '⚠️ اکانت تست خاموش است: دکمه‌اش در منوی ربات نیست و کسی اکانت تست تازه نمی‌گیرد.' : null,
            $none ? '⚠️ الان هیچ سروری اکانت تست نمی‌دهد، پس مشتری‌ها اکانت تست نمی‌گیرند.' : null,
            $hidden ? '⚠️ دکمه «اکانت تست» در منوی ربات نیست، پس مشتری‌ها به گرفتن اکانت تست در ربات راهی ندارند.' : null,
        ]));
    }

    protected function item(array $row): Button
    {
        $state = self::STATES[(string) ($row['state'] ?? '')] ?? '';
        $customer = Json::part($row, 'customer');
        $server = is_array($row['server'] ?? null) ? (string) ($row['server']['name'] ?? '') : 'سرور حذف شده';

        return Button::to(
            CatalogFmt::emoji($state) . ' ' . Fmt::cut((string) ($customer['name'] ?? ''), 18) . ' · ' . Fmt::cut($server, 14) . ((bool) ($row['bought_after'] ?? false) ? ' · 🛒' : ''),
            TestScreen::ID,
            Nav::SHOW,
            (int) ($row['id'] ?? 0),
        );
    }

    /** The ways to fix what was warned of, then the page's other sections; how the list reads folded away. */
    protected function extra(View $view, Admin $admin): void
    {
        $state = $admin->state(self::ID);
        if (($state['off'] ?? false) === true && $admin->opens(TestSettingsScreen::ID)) {
            $view->row(Button::to(Words::turn(true, 'اکانت تست'), TestSettingsScreen::ID, 'e', 0, 1)->success());
        }
        if (($state['menu'] ?? false) === true) {
            $view->row(Button::to(Icons::KEYBOARDS . ' افزودن دکمه در کیبوردها', KeyboardsScreen::ID)->primary());
        }
        $view->row(Button::to(TestSettingsScreen::TITLE, TestSettingsScreen::ID), Button::to(TestServersScreen::TITLE, TestServersScreen::ID))
            ->help('هر اکانت تستی که مشتری‌ها گرفته‌اند، تازه‌ترین اول: وضعیتش، مشتری و سرورش، و 🛒 یعنی مشتری بعد از آن خرید کرد. حجم و مدت هر اکانت تست را هر سرور خودش دارد؛ سهمیه مشتری‌ها و حذف خودکار در «' . Fmt::e(Fmt::bare(TestSettingsScreen::TITLE)) . '» است.');
    }

    protected function tools(Admin $admin): array
    {
        return [$this->user > 0 ? Button::to('✖️ همه مشتری‌ها', self::ID, 'user', 0) : null];
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
