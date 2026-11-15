<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Lottery;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Links;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One night's draw (from the list's kept page, LotteryDrawsScreen): its entries, its prize and its winners — each with a
 * link to their page and to the service the prize went to —, or why there are none (the panel's row).
 */
final class LotteryDrawScreen extends BaseScreen
{
    public const ID = 'ltv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $rows = (array) ($admin->state(LotteryDrawsScreen::ID)['rows'] ?? []);
        $row = $rows[self::id($args)] ?? $rows[(string) self::id($args)] ?? null;
        if (!is_array($row)) {
            return $admin->open(LotteryDrawsScreen::ID);
        }
        $prize = Json::part($row, 'prize');
        $winners = array_values(array_filter(Json::part($row, 'winners'), is_array(...)));
        $entries = (int) ($row['entries'] ?? 0);

        $view = View::make('🎲 قرعه‌کشی ' . LotteryDrawsScreen::night($row['day'] ?? null), ...[...Words::PATH, 'قرعه‌کشی'])
            ->facts([
                'شانس‌ها' => Fmt::number($entries),
                'جایزه' => Fmt::e((string) ($prize['words'] ?? '')),
            ])
            ->line()
            ->line('🏆 <b>برنده‌ها</b>');
        if ($winners === []) {
            $view->line($entries === 0 ? 'بدون شرکت‌کننده' : 'جایزه به کسی داده نشد');
        }
        foreach ($winners as $n => $winner) {
            $customer = is_array($winner['customer'] ?? null) ? (int) ($winner['customer']['id'] ?? 0) : 0;
            $service = is_int($winner['subscription_id'] ?? null) ? $winner['subscription_id'] : 0;
            $view->line(Fmt::number($n + 1) . '. ' . Fmt::e(Words::customer($winner['customer'] ?? null)) . (is_string($winner['outcome'] ?? null) && $winner['outcome'] !== '' ? ' · ' . Fmt::e($winner['outcome']) : ''));
            $view->row(
                $customer > 0 ? Links::to($admin, '👤 ' . Words::customer($winner['customer'] ?? null), Links::CUSTOMER, 'show', $customer) : null,
                $service > 0 ? Links::to($admin, '📡 سرویس', Links::SERVICE, 'show', $service) : null,
            );
        }

        return $view->back(LotteryDrawsScreen::ID);
    }
}
