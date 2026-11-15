<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🏦 روش‌های پرداخت» (the owner's and an agent's, the panel's page): the ways to pay in checkout order — the built-in
 * wallet and the methods added —, each with its driver, how it settles, its cards or summary, the amounts it is offered
 * for and its switch, opening its own screen (PaymentMethodScreen); «➕ افزودن روش پرداخت»; and «تایید خودکار واریز»
 * (DepositSettingsScreen).
 */
final class PaymentMethodsScreen extends BaseScreen
{
    public const ID = 'pm';

    /** The shop menu the list is in. */
    private const HUB = 'hs';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $methods = Json::rows($admin->api->get('/payment-methods'), 'methods');
        $view = View::make('🏦 روش‌های پرداخت', 'مدیریت', 'فروشگاه')
            ->line('راه‌هایی که مشتری با آن‌ها پرداخت می‌کند؛ هر روش برای مبلغ‌های میان کمترین و بیشترین مبلغش پیشنهاد می‌شود، یک روش کارت به کارت چند کارت دارد، و ترتیب همین لیست ترتیب دکمه‌ها هنگام پرداخت است.');
        $buttons = [];
        foreach ($methods as $n => $method) {
            $view->line()
                ->line(Fmt::number($n + 1) . '. <b>' . Fmt::e((string) ($method['label'] ?? '')) . '</b> · ' . Fmt::e((string) ($method['driver_label'] ?? '')) . ' · ' . (Json::on($method, 'enabled') ? '🟢 فعال' : '⏸ غیرفعال'))
                ->line('   ' . Fmt::e(PaymentWords::kind($method)) . ' · ' . Fmt::number((int) ($method['counts']['payments'] ?? 0)) . ' پرداخت');
            foreach (PaymentWords::details($method) as $line) {
                $view->line('   ' . $line);
            }
            $buttons[] = Button::to((Json::on($method, 'enabled') ? '🟢 ' : '⏸ ') . (string) ($method['label'] ?? ''), PaymentMethodScreen::ID, 'show', (int) ($method['id'] ?? 0));
        }
        $view->note('کیف پول همیشه هست و حذف نمی‌شود؛ فقط نامش، کمترین و بیشترین مبلغش و فعال یا غیرفعال بودنش عوض می‌شود.');

        return $view->grid(2, ...$buttons)
            ->row(Button::to('➕ افزودن روش پرداخت', PaymentDriversScreen::ID))
            ->row(Button::to('⚡️ تایید خودکار واریز', DepositSettingsScreen::ID))
            ->back(self::HUB);
    }
}
