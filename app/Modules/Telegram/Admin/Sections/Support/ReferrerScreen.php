<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One referrer (a row of ReferrersScreen's page): who, how many their link brought, what it earned them and when the
 * last came, in one card — the alerts that stand on them said over it —, and the ways on, as the panel's row links: the
 * ones they brought, their alerts, their page.
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
        $earned = Words::figure($row['earned'] ?? null);
        $view = View::make(ReferralsScreen::REFERRER . ' معرف ' . Words::name($user), ...ReferralsScreen::under(Fmt::bare(ReferralsScreen::REFERRERS)));
        if ($alerts > 0) {
            $view->line(ReferralsScreen::ALERT . ' ' . Fmt::number($alerts) . ' هشدار روی این معرف است؛ تا رفع آن، هدیه شروع و جایزه دعوت به او داده نمی‌شود.');
        }
        $view->card([
            'معرف' => Words::account($user) . Words::banned($row['status'] ?? null),
            'زیرمجموعه‌ها' => Fmt::number((int) ($row['referrals'] ?? 0)) . ' نفر',
            'پورسانت دریافتی' => (float) $earned > 0 ? Fmt::money($earned) : Fmt::NONE,
            'آخرین زیرمجموعه' => Words::ago(is_string($row['last_referral_at'] ?? null) ? $row['last_referral_at'] : null),
        ]);

        return $view->grid(
            1,
            $alerts > 0 ? Button::to(ReferralsScreen::ALERT . ' هشدارهای این معرف', ReferralAlertsScreen::ID, 'who', $id)->primary() : null,
            Button::to(ReferralsScreen::INVITEE . ' زیرمجموعه‌های این معرف', InviteesScreen::ID, 'ref', $id),
            Links::customer($admin, $user, 'صفحه مشتری'),
        )->back(ReferrersScreen::ID);
    }
}
