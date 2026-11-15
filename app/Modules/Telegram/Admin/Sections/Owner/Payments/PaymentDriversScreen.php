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
 * «➕ افزودن روش پرداخت», its first step (the panel's picker): how the customer pays — each gateway driver by its name
 * and how it settles; a built-in one (the wallet) said, not offered: every shop has it. Its form follows
 * (PaymentMethodFormScreen).
 */
final class PaymentDriversScreen extends BaseScreen
{
    public const ID = 'pma';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $view = View::make('➕ افزودن روش پرداخت', 'مدیریت', 'فروشگاه', 'روش‌های پرداخت')
            ->line('مشتری چطور پرداخت می‌کند؟')
            ->line();
        $buttons = [];
        foreach ((array) ($admin->api->get('/payment-methods/drivers')['drivers'] ?? []) as $driver) {
            if (!is_array($driver) || !is_string($driver['key'] ?? null)) {
                continue;
            }
            $traits = Json::part($driver, 'traits');
            $kind = PaymentWords::KINDS[(string) ($traits['kind'] ?? '')] ?? null;
            $builtin = Json::on($traits, 'builtin');
            $view->line('• <b>' . Fmt::e((string) ($driver['label'] ?? '')) . '</b>' . ($kind !== null ? ' · ' . Fmt::e($kind) : '') . ($builtin ? ' · داخلی؛ همیشه هست' : ''));
            if (!$builtin) {
                $buttons[] = Button::to((string) ($driver['label'] ?? ''), PaymentMethodFormScreen::ID, 'new', $driver['key']);
            }
        }

        return $view->grid(2, ...$buttons)->back(PaymentMethodsScreen::ID);
    }
}
