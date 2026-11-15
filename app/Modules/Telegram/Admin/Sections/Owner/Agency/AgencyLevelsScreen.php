<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🏷 سطح‌های نمایندگی» (the panel's levels section): the levels in the order the bot lists them — a name, a price per
 * GB and how many agents are on it —, each opening its own screen (AgencyLevelScreen), and «➕ افزودن سطح».
 */
final class AgencyLevelsScreen extends BaseScreen
{
    public const ID = 'agk';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $levels = Json::rows($admin->api->get('/agency/levels'), 'levels');
        $view = View::make('🏷 سطح‌های نمایندگی', 'مدیریت', 'نمایندگان')
            ->line('هر سطح یک نام و قیمت هر گیگابایت حجم است؛ نماینده حجمی را که رباتش می‌فروشد با قیمت سطحش می‌خرد.');
        if ($levels === []) {
            $view->line()->line('<b>هنوز سطحی ندارید</b>')
                ->line('هر سطح یک نام و یک قیمت برای هر گیگابایت حجم است؛ مثلا برنزی ۴٬۰۰۰ و طلایی ۳٬۰۰۰ تومان. درخواست‌ها روی یکی از سطح‌ها تایید می‌شوند.');
        }
        $buttons = [];
        foreach ($levels as $n => $level) {
            $view->line(Fmt::number($n + 1) . '. <b>' . Fmt::e((string) ($level['name'] ?? '')) . '</b> · هر گیگابایت ' . Fmt::money((string) ($level['price_per_gb'] ?? '0')) . ' · ' . Fmt::number((int) ($level['counts']['agents'] ?? 0)) . ' نماینده');
            $buttons[] = Button::to((string) ($level['name'] ?? ''), AgencyLevelScreen::ID, 'show', (int) ($level['id'] ?? 0));
        }

        return $view->grid(2, ...$buttons)->row(Button::to('➕ افزودن سطح', AgencyLevelFormScreen::ID, 'new'))->back(AgencyScreen::ID);
    }
}
