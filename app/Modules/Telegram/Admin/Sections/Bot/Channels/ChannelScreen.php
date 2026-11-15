<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Channels;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One required channel (from the panel's list of them — it has no route for one): its name, its link, whether the bot is
 * still its admin and when that was last looked at; «🔄 بررسی دوباره», moved up or down the list, and «🗑 حذف», asked
 * first with the panel's words.
 *
 * Actions: `check`, `up`, `down`, `del` (asks), `gone` — each with the id.
 */
final class ChannelScreen extends BaseScreen
{
    public const ID = 'chv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $channels = ChannelsScreen::channels($admin);
        $at = self::at($channels, self::id($args));

        return $at === null ? $admin->open(ChannelsScreen::ID, ChannelsScreen::VIEW) : $this->view($channels, $at);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        $channels = ChannelsScreen::channels($admin);
        $at = self::at($channels, $id);
        if ($at === null) {
            return $admin->open(ChannelsScreen::ID, ChannelsScreen::VIEW);
        }
        $title = (string) ($channels[$at]['title'] ?? '');
        switch ($action) {
            case 'check':
                $channel = (array) ($admin->api->post("/bot/channels/{$id}/check")['channel'] ?? []);
                if (Json::on($channel, 'bot_is_admin')) {
                    $admin->notice('✅ ربات در «' . $title . '» مدیر است');
                } else {
                    $admin->alert('ربات دیگر در «' . $title . '» مدیر نیست.');
                }
                $channels[$at] = $channel;

                return $this->view($channels, $at);
            case 'up':
            case 'down':
                $target = $action === 'up' ? $at - 1 : $at + 1;
                if (!isset($channels[$target])) {
                    return $this->view($channels, $at);
                }
                [$channels[$at], $channels[$target]] = [$channels[$target], $channels[$at]];
                $channels = Json::rows($admin->api->post('/bot/channels/reorder', ['ids' => array_map(static fn(array $channel): int => (int) ($channel['id'] ?? 0), $channels)]), 'channels');
                $admin->notice('✅ ترتیب کانال‌ها ذخیره شد');
                $at = self::at($channels, $id);

                return $at === null ? $admin->open(ChannelsScreen::ID, ChannelsScreen::VIEW) : $this->view($channels, $at);
            case 'del':
                return $admin->confirm(
                    'این کانال حذف شود؟',
                    Button::to('🗑 بله، حذف شود', self::ID, 'gone', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    '«' . Fmt::e($title) . '» از لیست کانال‌های اجباری برداشته می‌شود. ربات از کانال بیرون نمی‌رود؛ فقط عضویت در آن دیگر شرط استفاده از ربات نیست.',
                );
            case 'gone':
                $admin->api->delete("/bot/channels/{$id}");
                $admin->notice('🗑 کانال حذف شد');

                return $admin->open(ChannelsScreen::ID, ChannelsScreen::VIEW);
        }

        return $this->view($channels, $at);
    }

    /**
     * @param list<array<string, mixed>> $channels
     */
    private function view(array $channels, int $at): View
    {
        $channel = $channels[$at];
        $id = (int) ($channel['id'] ?? 0);
        $link = is_string($channel['link'] ?? null) ? $channel['link'] : null;
        $admin = Json::on($channel, 'bot_is_admin');
        $view = View::make('📢 ' . (string) ($channel['title'] ?? ''), 'مدیریت', 'ربات', 'کانال‌های اجباری')
            ->facts([
                'لینک' => $link === null ? '⚠️ لینک عضویت ندارد و از کسی خواسته نمی‌شود' : Fmt::ltr(is_string($channel['username'] ?? null) ? '@' . $channel['username'] : $link),
                'نوع' => ($channel['type'] ?? null) === 'channel' ? 'کانال' : 'گروه',
                'وضعیت ربات' => $admin ? '✅ مدیر است' : '⚠️ مدیر نیست',
                'بررسی' => Fmt::date(is_string($channel['checked_at'] ?? null) ? $channel['checked_at'] : null, true),
                'جای در لیست' => Fmt::number($at + 1) . ' از ' . Fmt::number(count($channels)),
            ]);
        if (!$admin) {
            $view->line()->line('⚠️ تا وقتی ربات مدیر این کانال نشود، کانال بررسی نمی‌شود و جلوی کسی را نمی‌گیرد. بعد از درست کردن، «بررسی دوباره» را بزنید.');
        }
        $view->row(Button::to('🔄 بررسی دوباره', self::ID, 'check', $id), ...($link !== null && str_starts_with($link, 'https://') ? [Button::url('🔗 باز کردن', $link)] : []))
            ->row(
                $at > 0 ? Button::to('🔼 بالاتر', self::ID, 'up', $id) : null,
                $at < count($channels) - 1 ? Button::to('🔽 پایین‌تر', self::ID, 'down', $id) : null,
            )
            ->row(Button::to('🗑 حذف کانال', self::ID, 'del', $id));

        return $view->back(ChannelsScreen::ID, ChannelsScreen::VIEW);
    }

    /**
     * Where the channel of `$id` is in the list; null when it is not there.
     *
     * @param list<array<string, mixed>> $channels
     */
    private static function at(array $channels, int $id): ?int
    {
        foreach ($channels as $index => $channel) {
            if ((int) ($channel['id'] ?? 0) === $id) {
                return $index;
            }
        }

        return null;
    }
}
