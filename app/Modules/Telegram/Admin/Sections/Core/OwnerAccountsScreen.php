<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Core;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «👑 حساب تلگرام مالک» (the owner's): the Telegram accounts that are the owner in the main bot (OwnerLinks, through the
 * panel's «مدیریت از ربات» routes) as a summary card, a one-time link for one more — opened from that account, copied at
 * a tap —, and an account let go, asked first (red: it loses the shop's management from the bot).
 *
 * Actions: `link` (a new link), `drop` (telegram id: asks), `dropped` (telegram id: lets it go).
 */
final class OwnerAccountsScreen extends BaseScreen
{
    public const ID = 'ol';

    public const TITLE = Icons::OWNER . ' حساب تلگرام مالک';

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
                $url = is_string($link['url'] ?? null) ? $link['url'] : null;
                $admin->notice('✅ لینک تازه ساخته شد.');

                return $this->view($admin, $answer, $url, is_string($link['expires_at'] ?? null) ? $link['expires_at'] : null);
            case 'drop':
                $self = $id === $admin->seat->user->telegram_id;

                return $admin->confirm(
                    'این حساب دیگر مالک نباشد؟',
                    Button::to(Words::yes('برداشته شود'), self::ID, 'dropped', $id),
                    Button::to(Words::CANCEL, self::ID),
                    $self ? 'این حساب خود شماست: اگر لینک آن را مدیر ربات کرده بود، بعد از آن از ربات مدیریت نمی‌کنید تا از پنل دوباره وصلش کنید؛ اگر از پیش مدیر ربات بوده‌اید، تا «مدیران ربات» روشن است کارهای روزانه برایتان می‌ماند.' : 'این حساب دیگر مالک نیست و از ربات فروشگاه را مدیریت نمی‌کند؛ با یک لینک تازه دوباره وصل می‌شود.',
                    danger: true,
                );
            case 'dropped':
                $admin->api->delete('/settings/owner-telegram/' . $id);
                $admin->notice('✅ برداشته شد.');
                if ($id === $admin->seat->user->telegram_id) {
                    // An admin before its link stays one (OwnerLinks): the daily work, while «مدیران ربات» lets admins in.
                    $view = View::make(self::TITLE);

                    return $admin->seat->user->refresh()->isAdmin()
                        ? $view->line('این حساب دیگر مالک نیست؛ مدیر ربات می‌ماند، چون پیش از وصل شدن هم مدیر ربات بود: تا «مدیران ربات» روشن است، کارهای روزانه را از «⚙️ مدیریت» دارد.')->back(Screens::HOME)
                        : $view->line('این حساب دیگر مالک نیست؛ مدیریت از ربات برایش بسته شد.');
                }

                return $this->show($admin, []);
        }

        return $this->show($admin, $args);
    }

    /**
     * The accounts — each a line of the summary, each let go from its own button (red, last) — and, just made, the new
     * link to open from the other account, to copy.
     *
     * @param array<string, mixed> $answer
     */
    private function view(Admin $admin, array $answer, ?string $url = null, ?string $until = null): View
    {
        $view = View::make(self::TITLE, Words::ROOT, Fmt::bare(Words::SETTINGS))
            ->line('حساب‌های تلگرامی که در ربات اصلی مالک‌اند: همه بخش‌های فروشگاه، مثل ورود به پنل.');
        $facts = [];
        $buttons = [];
        $accounts = array_filter(Json::rows($answer, 'accounts'), static fn(array $account): bool => is_int($account['telegram_id'] ?? null));
        $names = array_map(static fn(array $account): string => is_string($account['name'] ?? null) ? $account['name'] : 'بی‌نام', $accounts);
        $shared = array_count_values($names);
        foreach ($accounts as $at => $account) {
            $id = (int) $account['telegram_id'];
            // Two accounts of one name (two «بی‌نام») told apart by their number: a line and a button each.
            $name = $names[$at] . ($shared[$names[$at]] > 1 ? ' (' . Fmt::digits($id) . ')' : '');
            $handle = is_string($account['username'] ?? null) ? ' · ' . Fmt::ltr('@' . $account['username']) : '';
            $self = $id === $admin->seat->user->telegram_id ? ' (شما)' : '';
            $facts[$name . $self] = Fmt::code((string) $id) . $handle;
            $buttons[] = Button::to('🗑 برداشتن ' . $name . $self, self::ID, 'drop', $id)->danger();
        }
        $view->card($facts);
        if ($url !== null) {
            $view->section('🔗 لینک تازه')
                ->card(['لینک' => Fmt::code($url), 'کار می‌کند تا' => Fmt::date($until, true)])
                ->note('این لینک را با همان حساب تلگرامی باز کنید که باید مالک شود؛ فقط یک بار کار می‌کند.');
        } elseif (is_string($answer['link_open_until'] ?? null)) {
            $view->line('⏳ یک لینک باز تا ' . Fmt::date($answer['link_open_until'], true));
        }

        // The way to add first, the ways to let go last (before the way back).
        return $view->row($url === null ? null : Button::copy(Words::copy('لینک'), $url))
            ->row(Button::to(Words::add('حساب دیگر'), self::ID, 'link')->success())
            ->grid(1, ...$buttons)
            ->back('hc');
    }
}
