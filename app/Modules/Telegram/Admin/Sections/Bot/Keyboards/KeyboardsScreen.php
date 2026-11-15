<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Keyboards;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «⌨️ کیبوردها» (the owner's and an agent's): the bot's keyboards the panel lists — today the start menu alone, which
 * this opens straight away (KeyboardScreen) —, each with whether it is still the built-in layout.
 */
final class KeyboardsScreen extends BaseScreen
{
    public const ID = 'kb';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $keyboards = KeyboardScreen::keyboards($admin);
        if (count($keyboards) === 1) {
            return $admin->open(KeyboardScreen::ID, 'show', [(string) $keyboards[0]['name']]);
        }
        $view = View::make('⌨️ کیبوردها', 'مدیریت', 'ربات')
            ->line('دکمه‌هایی که ربات به مشتری نشان می‌دهد؛ چه دکمه‌ای، با چه متن و آیکونی، در کدام ردیف و به چه رنگی.');
        foreach ($keyboards as $keyboard) {
            $view->row(Button::to('⌨️ ' . (string) ($keyboard['title'] ?? $keyboard['name']) . (Json::on($keyboard, 'is_default') ? ' · چیدمان پیش‌فرض' : ''), KeyboardScreen::ID, 'show', (string) $keyboard['name']));
        }

        return $view->back('hb');
    }
}
