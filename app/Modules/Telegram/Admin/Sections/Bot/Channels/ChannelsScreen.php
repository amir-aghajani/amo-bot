<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Channels;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\Origin;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «📢 کانال‌های اجباری» (the owner's and an agent's; the panel's «کانال‌ها» section): the rule's switch (`channels`), the
 * channels and groups a customer must join, in order — those the bot is no admin of any more warned of —, each opening
 * its own screen (ChannelScreen), and «➕ افزودن کانال» by its link, which the panel refuses unless the bot is an admin
 * there. Each change applies at once. Opened from a 0.10.0 window's «🤖 تنظیمات ربات», its «⬅️ بازگشت» goes back there
 * (Origin).
 *
 * Actions: `join` (1 on, 0 off — on asked first: `y` once asked), `add` (asks), `v` (the list again, its origin kept).
 */
final class ChannelsScreen extends BaseScreen
{
    public const ID = 'ch';

    public const TITLE = Icons::CHANNELS . ' کانال‌های اجباری';

    /** The list again where it was left, its origin kept: the way back of a channel's screen. */
    public const VIEW = 'v';

    public function __construct(private readonly BotSettingsScreen $settings) {}

    public function show(Admin $admin, array $args): View
    {
        Origin::opened($admin, self::ID, $args);

        return $this->view($admin);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'join':
                if (!isset($args[0])) {
                    break;
                }
                $on = $args[0] === '1';
                $values = $this->source()->load($admin);
                if (($values['join_required'] ?? false) === $on) {
                    $admin->notice('عضویت اجباری همین حالا ' . ($on ? 'روشن' : 'خاموش') . ' است');
                    break;
                }
                if ($on && ($args[1] ?? '') !== 'y') {
                    // On holds back every customer who is no member: asked first; off is one tap.
                    return $admin->confirm(
                        'عضویت اجباری روشن شود؟',
                        Button::to(Words::yes('روشن شود'), self::ID, 'join', 1, 'y'),
                        Button::to(Words::CANCEL, self::ID, self::VIEW),
                        'از این به بعد مشتری‌ای که عضو همه کانال‌های لیست نیست، به جای منو لینک کانال‌ها و دکمه «عضو شدم» را می‌بیند (بعد از تایید شماره). مدیریت فروشگاه معاف است.'
                            . (self::channels($admin) === [] ? ' لیست هنوز خالی است؛ تا کانالی اضافه نشود، از کسی چیزی خواسته نمی‌شود.' : ''),
                    );
                }
                $values = $this->source()->save($admin, $values, 'join_required', $on);
                $admin->notice(Json::on($values, 'join_required') ? '✅ عضویت اجباری روشن شد' : '⏸ عضویت اجباری خاموش شد');
                break;
            case 'add':
                return $admin->ask((new Prompt('افزودن کانال', self::ID, 'add', [], 'لینک عمومی یا نام کاربری کانال یا گروه؛ برای کانال خصوصی شناسه عددی آن را بدهید، لینک عضویت را ربات خودش می‌سازد. ربات باید پیش از این مدیر آن‌جا شده باشد.', Button::to(Words::CANCEL, self::ID, self::VIEW)))
                    ->example('@mychannel'));
        }

        return $this->view($admin);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action === 'add') {
            $channel = (array) ($admin->api->post('/bot/channels', ['link' => $answer->latin()])['channel'] ?? []);
            $admin->notice('✅ «' . (string) ($channel['title'] ?? '') . '» اضافه شد');
        }

        return $this->view($admin);
    }

    /**
     * The rule's switch and the channels, as the panel has them now.
     *
     * @throws ApiRefusal
     */
    private function view(Admin $admin): View
    {
        $required = ($this->source()->load($admin)['join_required'] ?? false) === true;
        $channels = self::channels($admin);
        $lost = array_filter($channels, static fn(array $channel): bool => ($channel['bot_is_admin'] ?? false) !== true);

        $view = View::make(self::TITLE, ...BotScreens::PATH);
        if (!$required) {
            $view->line('ℹ️ عضویت اجباری خاموش است و این لیست اعمال نمی‌شود.');
        } elseif ($channels === []) {
            $view->line('ℹ️ تا وقتی این لیست خالی است، چیزی از مشتری خواسته نمی‌شود.');
        }
        if ($lost !== []) {
            $view->line('⚠️ ربات در ' . Fmt::e(implode('، ', array_map(static fn(array $channel): string => '«' . (string) ($channel['title'] ?? '') . '»', $lost))) . ' مدیر نیست؛ تا وقتی درست نشود، آن کانال بررسی نمی‌شود و جلوی کسی را نمی‌گیرد.');
        }
        $view->card([
            'عضویت اجباری' => Fmt::onOff($required),
            'کانال‌ها' => Fmt::number(count($channels)) . ($lost !== [] ? ' · ' . Fmt::number(count($lost)) . ' بدون دسترسی ربات' : ''),
        ]);
        if ($channels === []) {
            $view->line('<b>هنوز کانالی اضافه نشده.</b> اولین کانال را با لینکش اضافه کنید.');
        }
        foreach ($channels as $channel) {
            $view->row(Button::to((Json::on($channel, 'bot_is_admin') ? '🟢 ' : '⚠️ ') . (string) ($channel['title'] ?? ''), ChannelScreen::ID, 'show', (int) ($channel['id'] ?? 0)));
        }

        return $view->row(
            Button::to(Words::add('کانال'), self::ID, 'add')->success(),
            Button::to(Words::turn(!$required, 'عضویت اجباری'), self::ID, 'join', $required ? 0 : 1),
        )
            ->help('مشتری باید عضو همه کانال‌ها و گروه‌های این لیست باشد؛ وگرنه به جای منو، لینک کانال‌ها و دکمه «عضو شدم» را می‌بیند. بعد از تایید شماره بررسی می‌شود و فقط برای مشتری‌ها؛ مدیریت فروشگاه (مالک، نماینده و مدیران ربات) معاف است. ربات باید مدیر هر کانال باشد تا عضویت مشتری‌ها را ببیند. 🟢 ربات مدیر است · ⚠️ مدیر نیست.')
            ->back(Origin::back($admin, self::ID, BotScreens::MENU));
    }

    /**
     * The required channels, in their order (GET /bot/channels).
     *
     * @return list<array<string, mixed>>
     * @throws ApiRefusal
     */
    public static function channels(Admin $admin): array
    {
        return Json::rows($admin->api->get('/bot/channels'), 'channels');
    }

    private function source(): BotGroupSource
    {
        return new BotGroupSource('channels', $this->settings);
    }
}
