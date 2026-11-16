<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Lottery;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Links;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One night's draw (from the list's kept page, LotteryDrawsScreen): its summary card — entries, prize —, its winners, each
 * with what they got and buttons to their page and to the service the prize went to — or why there are none (the
 * panel's row).
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
        $row = ListScreen::keptRow($admin, LotteryDrawsScreen::ID, self::id($args));
        if ($row === null) {
            return $admin->open(LotteryDrawsScreen::ID);
        }
        $prize = Json::part($row, 'prize');
        $winners = Json::rows($row, 'winners');
        $entries = (int) ($row['entries'] ?? 0);

        $view = View::make(Icons::LOTTERY . ' قرعه‌کشی ' . LotteryDrawsScreen::night($row['day'] ?? null), ...Marketing::in(Marketing::LOTTERY))
            ->card([
                'شانس‌ها' => Fmt::number($entries),
                'جایزه هر برنده' => Fmt::e((string) ($prize['words'] ?? '')),
            ])
            ->section('🏆 برنده‌ها');
        if ($winners === []) {
            $view->line($entries === 0 ? 'بدون شرکت‌کننده' : 'جایزه به کسی داده نشد');
        }
        // Each winner said once, with what they got; their buttons say only where they go, by the winner's number.
        foreach ($winners as $n => $winner) {
            $customer = is_array($winner['customer'] ?? null) ? (int) ($winner['customer']['id'] ?? 0) : 0;
            $service = is_int($winner['subscription_id'] ?? null) ? $winner['subscription_id'] : 0;
            $place = Fmt::number($n + 1);
            $view->line($place . '. ' . Fmt::e(Marketing::customer($winner['customer'] ?? null)) . (is_string($winner['outcome'] ?? null) && $winner['outcome'] !== '' ? ': ' . Fmt::e($winner['outcome']) : ''));
            $view->row(
                $customer > 0 ? Links::to($admin, Icons::CUSTOMERS . ' برنده ' . $place, Links::CUSTOMER, 'show', $customer) : null,
                $service > 0 ? Links::to($admin, Icons::SERVICES . ' سرویس برنده ' . $place, Links::SERVICE, 'show', $service) : null,
            );
        }

        return $view->back(LotteryDrawsScreen::ID);
    }
}
