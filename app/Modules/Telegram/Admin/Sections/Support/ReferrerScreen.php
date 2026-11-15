<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One referrer (a row of ReferrersScreen's page): who, how many their link brought, what it earned them, when the last
 * came and the alerts that stand on them — and the ways on, as the panel's row links: the ones they brought, their
 * alerts, their page.
 */
final class ReferrerScreen extends KeptRowScreen
{
    public const ID = 'rfrv';

    protected function list(): string
    {
        return ReferrersScreen::ID;
    }

    protected function view(Admin $admin, array $row): View
    {
        $id = (int) ($row['id'] ?? 0);
        $user = is_array($row['user'] ?? null) ? $row['user'] : null;
        $alerts = (int) ($row['alerts'] ?? 0);
        $earned = is_numeric($row['earned'] ?? null) ? (string) $row['earned'] : '0';
        $view = View::make('👥 معرف ' . Words::name($user), 'مدیریت', 'فروش', 'زیرمجموعه‌گیری', 'معرف‌ها')
            ->facts([
                'معرف' => Words::account($user) . Words::banned($row['status'] ?? null),
                'زیرمجموعه‌ها' => Fmt::number((int) ($row['referrals'] ?? 0)) . ' نفر',
                'پورسانت دریافتی' => (float) $earned > 0 ? Fmt::money($earned) : Fmt::NONE,
                'آخرین زیرمجموعه' => Words::ago(is_string($row['last_referral_at'] ?? null) ? $row['last_referral_at'] : null),
                'هشدارها' => $alerts > 0 ? '⚠️ ' . Fmt::number($alerts) . ' هشدار؛ هدیه شروع و جایزه دعوت نگه داشته می‌شود' : null,
            ]);

        return $view->grid(
            1,
            Button::to('🙋 زیرمجموعه‌های این معرف', InviteesScreen::ID, 'ref', $id),
            $alerts > 0 ? Button::to('⚠️ هشدارهای این معرف', ReferralAlertsScreen::ID, 'who', $id) : null,
            Links::customer($admin, $user, 'صفحه مشتری'),
        )->back(ReferrersScreen::ID);
    }
}
