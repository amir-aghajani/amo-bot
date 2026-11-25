<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Bot\Keyboards\KeyboardScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🤝 نمایندگان» (the owner's, the panel's agents page): what keeps the program from working said first (switched off, no
 * menu button in the main bot, no level yet), then its numbers in one card — the program's switch, agents, running bots,
 * requests waiting (the agents' renewals among them said), levels and the starting credit —, and its four sections:
 * requests (with what waits), agents, levels and the rules. The agency runs in the main shop whichever shop the owner
 * works (the API's `shop.main`), as the panel shows it in every shop.
 */
final class AgencyScreen extends BaseScreen
{
    public const ID = 'ag';

    public const TITLE = Icons::AGENTS . ' نمایندگان';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    /**
     * The sections the agency's screens are in: «مدیریت › نمایندگان».
     *
     * @return list<string>
     */
    public static function path(): array
    {
        return [Words::ROOT, Fmt::bare(self::TITLE)];
    }

    public function show(Admin $admin, array $args): View
    {
        $summary = (array) ($admin->api->get('/agency')['summary'] ?? []);
        $rules = (array) ($admin->api->get('/agency/settings')['settings'] ?? []);
        $pending = (int) ($summary['pending'] ?? 0);
        // Of those waiting (both kinds), the agents' renewals: said beside them once there is one.
        $renewals = $pending > 0 ? (int) (((array) ($admin->api->get('/agency/requests', ['status' => 'pending', 'kind' => 'renewal'])['meta'] ?? []))['total'] ?? 0) : 0;
        $on = ($rules['enabled'] ?? false) === true;

        $view = View::make(self::TITLE, Words::ROOT);
        $fix = null;
        if (!$on) {
            $view->line('⏸ نمایندگی خاموش است: درخواست تازه‌ای ثبت نمی‌شود و دکمه نمایندگی برای مشتری‌ها دیده نمی‌شود. نماینده‌ها، ربات‌ها و حسابشان سر جایشان می‌مانند.');
        } elseif ($admin->seat->shop()->isMain() && !KeyboardScreen::hasButton($admin, 'agency')) {
            $view->line('⚠️ دکمه «نمایندگی» در منوی ربات نیست، پس مشتری‌ها به درخواست نمایندگی و حساب نماینده‌ها راهی ندارند.');
            $fix = $admin->opens(KeyboardScreen::ID) ? Button::to(Icons::KEYBOARDS . ' افزودن دکمه در منوی شروع', KeyboardScreen::ID, 'show', 'start')->primary() : null;
        }
        if ((int) ($summary['levels'] ?? 0) === 0) {
            $view->line('ℹ️ هنوز سطحی تعریف نشده است؛ درخواست‌ها روی یک سطح تایید می‌شوند.');
        }
        $credit = (int) round((float) ($rules['default_credit'] ?? 0));
        $view->card([
            'نمایندگی' => Fmt::onOff($on),
            'نماینده‌ها' => Fmt::number((int) ($summary['agents'] ?? 0)),
            'ربات‌های فعال' => Fmt::number((int) ($summary['bots'] ?? 0)),
            'درخواست‌های در انتظار' => Fmt::number($pending) . ($renewals > 0 ? ' (' . Fmt::number($renewals) . ' تمدید)' : ''),
            'سطح‌ها' => Fmt::number((int) ($summary['levels'] ?? 0)),
            'اعتبار اولیه' => $credit > 0 ? Fmt::money((string) $credit) : 'ندارد',
        ]);

        return $view
            ->row($fix)
            ->grid(
                2,
                Button::to('📥 درخواست‌ها' . Fmt::badge($pending), AgencyRequestsScreen::ID),
                Button::to(AgentsScreen::TITLE, AgentsScreen::ID),
                Button::to('🏷 سطح‌ها', AgencyLevelsScreen::ID),
                Button::to(AgencySettingsScreen::TITLE, AgencySettingsScreen::ID),
            )
            ->help('مشتری از صفحه «نمایندگی» ربات درخواست می‌دهد؛ با تایید، روی یک سطح نماینده می‌شود و ربات فروش خودش را دارد. حجمی را که رباتش می‌فروشد با قیمت سطحش از شما می‌خرد و تا اعتبارش می‌تواند بدهکار شود. نماینده‌ها هم برای تمدید نمایندگی درخواست می‌فرستند؛ با تایید، تاریخ پایانشان جلو می‌رود.')
            ->back(Screens::HOME)
            ->refresh(self::ID);
    }
}
