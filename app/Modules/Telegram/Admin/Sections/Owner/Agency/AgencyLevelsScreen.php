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
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🏷 سطح‌های نمایندگی» (the panel's levels section): the levels in the order the bot lists them — a button each, the row:
 * its name, its price per GB and how many agents are on it —, each opening its own screen (AgencyLevelScreen), and
 * «➕ افزودن سطح».
 */
final class AgencyLevelsScreen extends BaseScreen
{
    public const ID = 'agk';

    public const TITLE = '🏷 سطح‌های نمایندگی';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $levels = Json::rows($admin->api->get('/agency/levels'), 'levels');
        $view = View::make(self::TITLE, ...AgencyScreen::path());
        if ($levels === []) {
            $view->line('<b>هنوز سطحی ندارید.</b> هر سطح یک نام و یک قیمت برای هر گیگابایت حجم است؛ مثلا برنزی ۴٬۰۰۰ و طلایی ۳٬۰۰۰ تومان. درخواست‌ها روی یکی از سطح‌ها تایید می‌شوند.');
        } else {
            $view->total(count($levels), 'سطح');
        }
        foreach ($levels as $level) {
            $view->row(Button::to(
                (string) ($level['name'] ?? '') . ' · ' . Fmt::plain(Fmt::money((string) ($level['price_per_gb'] ?? '0'))) . ' هر گیگ · ' . Fmt::plain(Fmt::number((int) ($level['counts']['agents'] ?? 0))) . ' نماینده',
                AgencyLevelScreen::ID,
                'show',
                (int) ($level['id'] ?? 0),
            ));
        }

        return $view->row(Button::to(Words::add('سطح'), AgencyLevelFormScreen::ID, 'new')->success())
            ->help('هر سطح یک نام و قیمت هر گیگابایت حجم است؛ نماینده حجمی را که رباتش می‌فروشد با قیمت سطحش می‌خرد. ترتیب سطح‌ها همان ترتیبی است که مشتری در معرفی نمایندگی می‌بیند؛ در صفحه هر سطح با 🔼 و 🔽 جابه‌جا می‌شود.')
            ->back(AgencyScreen::ID);
    }
}
