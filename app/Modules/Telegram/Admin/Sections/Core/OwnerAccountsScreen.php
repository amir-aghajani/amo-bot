<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Core;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «👑 حساب تلگرام مالک» (the owner's): the Telegram accounts that are the owner in the main bot (OwnerLinks, through the
 * panel's «مدیریت از ربات» routes), a one-time link for one more — opened from that account —, and an account let go.
 *
 * Actions: `link` (a new link), `drop` (telegram id: asks), `dropped` (telegram id: lets it go).
 */
final class OwnerAccountsScreen extends BaseScreen
{
    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        return $this->view($admin, $admin->api->get('/settings/owner-telegram'));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'link':
                $answer = $admin->api->post('/settings/owner-telegram/link');
                $link = Json::part($answer, 'link');

                return $this->view($admin, $answer)->line()
                    ->line('🔗 این لینک را با همان حساب تلگرامی باز کنید که باید مالک شود؛ فقط یک بار و تا ' . Fmt::date(is_string($link['expires_at'] ?? null) ? $link['expires_at'] : null, true) . ' کار می‌کند:')
                    ->line(Fmt::code(is_string($link['url'] ?? null) ? $link['url'] : null));
            case 'drop':
                $self = $id === $admin->seat->user->telegram_id;

                return $admin->confirm(
                    'این حساب دیگر مالک نباشد؟',
                    Button::to('✔️ بله، برداشته شود', 'ol', 'dropped', $id),
                    Button::to('✖️ انصراف', 'ol'),
                    $self ? 'این حساب خود شماست: اگر لینک آن را مدیر ربات کرده بود، بعد از آن از ربات مدیریت نمی‌کنید تا از پنل دوباره وصلش کنید؛ اگر از پیش مدیر ربات بوده‌اید، تا «مدیران ربات» روشن است کارهای روزانه برایتان می‌ماند.' : '',
                );
            case 'dropped':
                $admin->api->delete('/settings/owner-telegram/' . $id);
                $admin->notice('✅ برداشته شد.');
                if ($id === $admin->seat->user->telegram_id) {
                    // An admin before its link stays one (OwnerLinks): the daily work, while «مدیران ربات» lets admins in.
                    $view = View::make('👑 حساب تلگرام مالک');

                    return $admin->seat->user->refresh()->isAdmin()
                        ? $view->line('این حساب دیگر مالک نیست؛ مدیر ربات می‌ماند، چون پیش از وصل شدن هم مدیر ربات بود: تا «مدیران ربات» روشن است، کارهای روزانه را از «⚙️ مدیریت» دارد.')->back(Screens::HOME)
                        : $view->line('این حساب دیگر مالک نیست؛ مدیریت از ربات برایش بسته شد.');
                }

                return $this->show($admin, []);
        }

        return $this->show($admin, $args);
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function view(Admin $admin, array $answer): View
    {
        $view = View::make('👑 حساب تلگرام مالک', 'مدیریت', 'تنظیمات')
            ->line('حساب‌های تلگرامی که در ربات اصلی مالک‌اند: همه بخش‌های فروشگاه، مثل ورود به پنل.');
        $buttons = [];
        foreach (Json::rows($answer, 'accounts') as $account) {
            if (!is_int($account['telegram_id'] ?? null)) {
                continue;
            }
            $name = is_string($account['name'] ?? null) ? $account['name'] : 'بی‌نام';
            $handle = is_string($account['username'] ?? null) ? ' · @' . $account['username'] : '';
            $self = $account['telegram_id'] === $admin->seat->user->telegram_id ? ' (شما)' : '';
            $view->line('• ' . Fmt::e($name . $handle) . ' · ' . Fmt::code((string) $account['telegram_id']) . Fmt::e($self));
            $buttons[] = Button::to('🗑 ' . $name . $self, 'ol', 'drop', $account['telegram_id']);
        }
        if (is_string($answer['link_open_until'] ?? null)) {
            $view->line('⏳ یک لینک باز تا ' . Fmt::date($answer['link_open_until'], true));
        }

        return $view->grid(1, ...$buttons)->row(Button::to('➕ وصل کردن حساب دیگر', 'ol', 'link'))->back('hc');
    }
}
