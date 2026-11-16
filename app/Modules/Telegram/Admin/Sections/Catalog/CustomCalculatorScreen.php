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
use App\Modules\Telegram\Admin\Ui\Words;
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

    public const TITLE = '🧮 ماشین حساب قیمت';

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

        $line = static fn(array|string $quote, int $amount, string $unit): string => is_string($quote)
            ? '⚠️ ' . Fmt::e($quote)
            : Persian::number($amount) . ' ' . $unit . ' × ' . Fmt::money($quote['unit']) . ' = ' . Fmt::money($quote['total']);
        $view = View::make(self::TITLE, ...CatalogFmt::under(Fmt::bare(CustomServicesScreen::TITLE)))
            ->card([
                CustomPricing::QUANTITIES['traffic']['title'] => $line($traffic, $gb, 'گیگابایت'),
                CustomPricing::QUANTITIES['days']['title'] => $line($time, $days, 'روز'),
                'قیمت سرویس' => is_string($traffic) || is_string($time) ? Words::NONE : Fmt::money($traffic['total'] + $time['total']),
            ])
            ->note('پیش از تخفیف‌های مشتری؛ با پله‌ها و مرزهای ذخیره‌شده.')
            ->row(Button::to(Words::edit('حجم'), self::ID, 'gb'), Button::to(Words::edit('مدت'), self::ID, 'days'));

        return CustomServicesScreen::backTo($view, CustomServicesScreen::opener($admin, self::ID, $args[0] ?? null));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $state = $admin->state(self::ID);

        return match ($action) {
            'gb' => $admin->ask((new Prompt('حجم (گیگابایت)', self::ID, 'gb', [], '', Button::to(Words::CANCEL, self::ID)))->current(Persian::number((int) ($state['gb'] ?? self::START['gb'])))->example('۶۰')),
            'days' => $admin->ask((new Prompt('مدت (روز)', self::ID, 'days', [], '', Button::to(Words::CANCEL, self::ID)))->current(Persian::number((int) ($state['days'] ?? self::START['days'])))->example('۳۰')),
            default => $this->show($admin, []),
        };
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action === 'gb' || $action === 'days') {
            $amount = $answer->amount($action === 'gb' ? Unit::Gigabyte : Unit::Day) ?? throw new ApiRefusal(422, Words::NOT_NUMBER);
            $admin->remember(self::ID, [$action => $amount]);
        }

        return $this->show($admin, []);
    }
}
