<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Support\Persian;
use App\Support\Unit;

/**
 * «ماشین حساب قیمت» of «سرویس دلخواه»: what a customer pays for the GB and days typed here, before their discounts, by
 * the tiers and bounds as they are saved — each quantity's rate and total, and their sum, or why there is none.
 *
 * Actions: `gb` (asks), `days` (asks).
 */
final class CustomCalculatorScreen extends BaseScreen
{
    public const ID = 'csc';

    /** What it starts from, as the panel's does: 50 GB for 30 days. */
    private const START = ['gb' => 50, 'days' => 30];

    public function allows(AdminSeat $seat): bool
    {
        return CustomServicesScreen::opens($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $state = $admin->state(self::ID);
        $gb = (int) ($state['gb'] ?? self::START['gb']);
        $days = (int) ($state['days'] ?? self::START['days']);
        $settings = BotGroupSource::settings($admin);
        $traffic = CustomPricing::quote($settings, 'traffic', $gb);
        $time = CustomPricing::quote($settings, 'days', $days);

        $view = View::make('🧮 ماشین حساب قیمت', 'مدیریت', 'فروشگاه', 'سرویس دلخواه')
            ->line('قیمتی که مشتری برای این حجم و مدت می‌بیند، پیش از تخفیف‌هایش؛ با پله‌ها و مرزهای ذخیره‌شده.')
            ->line();
        if (is_string($traffic) || is_string($time)) {
            $view->line('📶 ' . Persian::number($gb) . ' گیگابایت: ' . Fmt::e(is_string($traffic) ? $traffic : '✅'))
                ->line('📅 ' . Persian::number($days) . ' روز: ' . Fmt::e(is_string($time) ? $time : '✅'));
        } else {
            $view->line(Persian::number($gb) . ' گیگابایت × ' . Fmt::money($traffic['unit']) . ' = ' . Fmt::money($traffic['total']))
                ->line(Persian::number($days) . ' روز × ' . Fmt::money($time['unit']) . ' = ' . Fmt::money($time['total']))
                ->line('<b>سرویس ' . Persian::number($gb) . ' گیگابایتی ' . Persian::number($days) . ' روزه: ' . Fmt::money($traffic['total'] + $time['total']) . '</b>');
        }

        $view->row(Button::to('📶 حجم: ' . Persian::number($gb) . ' گیگابایت', self::ID, 'gb'), Button::to('📅 مدت: ' . Persian::number($days) . ' روز', self::ID, 'days'));

        return CustomServicesScreen::backTo($view, CustomServicesScreen::opener($admin, self::ID, $args[0] ?? null));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        return match ($action) {
            'gb' => $admin->ask(new Prompt('حجم (گیگابایت)', self::ID, 'gb')),
            'days' => $admin->ask(new Prompt('مدت (روز)', self::ID, 'days')),
            default => $this->show($admin, []),
        };
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action === 'gb' || $action === 'days') {
            $amount = $answer->amount($action === 'gb' ? Unit::Gigabyte : Unit::Day) ?? throw new ApiRefusal(422, 'یک عدد بنویسید.');
            $admin->remember(self::ID, [$action => $amount]);
        }

        return $this->show($admin, []);
    }
}
