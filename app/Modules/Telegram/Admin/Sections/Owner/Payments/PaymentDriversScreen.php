<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «➕ افزودن روش پرداخت», its first step (the panel's picker): how the customer pays — the gateway drivers grouped by
 * how they settle, each its name and what it is in one line (its description's first clause: the whole of it opens with
 * its form), and a button each; a built-in one (the wallet) said, not offered: every shop has it. Its form follows
 * (PaymentMethodFormScreen).
 */
final class PaymentDriversScreen extends BaseScreen
{
    public const ID = 'pma';

    /** Each way of settling's emoji over its drivers. */
    private const ICONS = ['manual' => Icons::CARD_TO_CARD, 'online' => Icons::PAYMENTS, 'instant' => Icons::WALLET];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $view = View::make(Words::add('روش پرداخت'), ...PaymentWords::under())
            ->line('مشتری چطور پرداخت کند؟ یکی را انتخاب کنید.');
        $groups = [];
        $builtin = [];
        foreach (Methods::drivers($admin) as $driver) {
            if (!is_string($driver['key'] ?? null)) {
                continue;
            }
            $traits = Json::part($driver, 'traits');
            if (Json::on($traits, 'builtin')) {
                $builtin[] = (string) ($driver['label'] ?? '');

                continue;
            }
            $groups[(string) ($traits['kind'] ?? '')][] = $driver;
        }
        // Card to card first, as the panel's picker: the way most shops start.
        uksort($groups, static fn(string $a, string $b): int => ($a === 'manual' ? 0 : 1) <=> ($b === 'manual' ? 0 : 1));
        $buttons = [];
        foreach ($groups as $kind => $drivers) {
            $view->section((self::ICONS[$kind] ?? Icons::PAYMENTS) . ' ' . (PaymentWords::KINDS[$kind] ?? 'سایر'));
            foreach ($drivers as $driver) {
                $view->line('• <b>' . Fmt::e((string) ($driver['label'] ?? '')) . '</b>: ' . Fmt::e(PaymentWords::gist((string) ($driver['description'] ?? ''))));
                $buttons[] = Button::to((string) ($driver['label'] ?? ''), PaymentMethodFormScreen::ID, 'new', $driver['key']);
            }
        }
        if ($builtin !== []) {
            $view->note(Fmt::e(implode('، ', $builtin)) . ' داخلی است و همیشه هست؛ از لیست روش‌ها تنظیمش کنید.');
        }

        return $view->tiles(...$buttons)->back(PaymentMethodsScreen::ID);
    }
}
