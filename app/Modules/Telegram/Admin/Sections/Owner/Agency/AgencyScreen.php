<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Bot\Keyboards\KeyboardScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🤝 نمایندگان» (the owner's, the panel's agents page): the program's numbers — agents, running bots, requests waiting,
 * levels and the starting credit —, what keeps it from working (switched off, no menu button in the main bot, no level
 * yet), and its four sections: requests, agents, levels and the rules. The agency runs in the main shop whichever shop
 * the owner works (the API's `shop.main`), as the panel shows it in every shop.
 */
final class AgencyScreen extends BaseScreen
{
    public const ID = 'ag';

    /** The keyboards' editor (the bot section's), linked when the menu lacks the program's button. */
    private const KEYBOARDS = 'kb';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $summary = (array) ($admin->api->get('/agency')['summary'] ?? []);
        $rules = (array) ($admin->api->get('/agency/settings')['settings'] ?? []);
        $pending = (int) ($summary['pending'] ?? 0);

        $view = View::make('🤝 نمایندگان', 'مدیریت');
        if (($rules['enabled'] ?? false) !== true) {
            $view->line('⛔️ نمایندگی خاموش است: درخواست تازه‌ای ثبت نمی‌شود و دکمه نمایندگی برای مشتری‌ها دیده نمی‌شود. نماینده‌ها، ربات‌ها و حسابشان سر جایشان می‌مانند.')->line();
        } elseif ($admin->seat->shop()->isMain() && !KeyboardScreen::hasButton($admin, 'agency')) {
            $view->line('⚠️ دکمه «نمایندگی» در منوی ربات نیست، پس مشتری‌ها به درخواست نمایندگی و حساب نماینده‌ها راهی ندارند.')->line();
            if ($admin->opens(self::KEYBOARDS)) {
                $view->row(Button::to('⌨️ افزودن دکمه در کیبوردها', self::KEYBOARDS));
            }
        }
        if ((int) ($summary['levels'] ?? 0) === 0) {
            $view->line('ℹ️ هنوز سطحی تعریف نشده است؛ درخواست‌ها روی یک سطح تایید می‌شوند.')->line();
        }
        $credit = (int) round((float) ($rules['default_credit'] ?? 0));
        $view->facts([
            'نماینده‌ها' => Fmt::number((int) ($summary['agents'] ?? 0)),
            'ربات‌های فعال' => Fmt::number((int) ($summary['bots'] ?? 0)),
            'درخواست‌های در انتظار' => Fmt::number($pending),
            'سطح‌ها' => Fmt::number((int) ($summary['levels'] ?? 0)) . ' · ' . ($credit > 0 ? 'اعتبار اولیه: ' . Fmt::money((string) $credit) : 'بدون اعتبار اولیه'),
        ]);
        $view->note('مشتری از صفحه «نمایندگی» ربات درخواست می‌دهد؛ با تایید، روی یک سطح نماینده می‌شود و ربات فروش خودش را دارد. حجمی را که رباتش می‌فروشد با قیمت سطحش از شما می‌خرد و تا اعتبارش می‌تواند بدهکار شود.');

        return $view
            ->row(Button::to('📥 درخواست‌ها' . Fmt::badge($pending), AgencyRequestsScreen::ID), Button::to('🤝 نماینده‌ها', AgentsScreen::ID))
            ->row(Button::to('🏷 سطح‌ها', AgencyLevelsScreen::ID), Button::to('⚙️ تنظیمات نمایندگی', AgencySettingsScreen::ID))
            ->back(Screens::HOME);
    }
}
