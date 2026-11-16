<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Keyboards;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «⌨️ کیبوردها» (the owner's and an agent's): the bot's keyboards the panel lists — today the start menu —, a button
 * each, the row: its name, whether it is still the built-in layout or changed, and how many buttons it has; each opening
 * its editor (KeyboardScreen).
 */
final class KeyboardsScreen extends BaseScreen
{
    public const ID = 'kb';

    public const TITLE = Icons::KEYBOARDS . ' کیبوردها';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $keyboards = KeyboardScreen::keyboards($admin);
        $view = View::make(self::TITLE, ...BotScreens::PATH)->total(count($keyboards), 'کیبورد');
        foreach ($keyboards as $keyboard) {
            $buttons = array_sum(array_map(static fn(mixed $row): int => is_array($row) ? count($row) : 0, (array) ($keyboard['rows'] ?? [])));
            $view->row(Button::to(
                Icons::KEYBOARDS . ' ' . (string) ($keyboard['title'] ?? $keyboard['name']) . ' · ' . KeyboardScreen::layout(Json::on($keyboard, 'is_default')) . ' · ' . Fmt::plain(Fmt::number($buttons)) . ' دکمه',
                KeyboardScreen::ID,
                'show',
                (string) $keyboard['name'],
            ));
        }

        return $view->help('دکمه‌هایی که ربات به مشتری نشان می‌دهد: چه دکمه‌ای، با چه متن و آیکونی، در کدام ردیف و به چه رنگی. هر تغییر همان لحظه ذخیره می‌شود و ربات از آن به بعد همان را نشان می‌دهد.')
            ->back(BotScreens::MENU);
    }
}
