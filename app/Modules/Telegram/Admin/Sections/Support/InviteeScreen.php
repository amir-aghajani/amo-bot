<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One invitee (a row of InviteesScreen's page): who, who brought them, what their payments earned that one and when
 * they came, in one card — and their pages, and the others the same referrer brought.
 */
final class InviteeScreen extends KeptRowScreen
{
    public const ID = 'rfiv';

    protected function list(): string
    {
        return InviteesScreen::ID;
    }

    protected function view(Admin $admin, array $row): View
    {
        $user = is_array($row['user'] ?? null) ? $row['user'] : null;
        $referrer = is_array($row['referrer'] ?? null) ? $row['referrer'] : null;
        $earned = Words::figure($row['earned'] ?? null);
        $joined = is_string($row['joined_at'] ?? null) ? $row['joined_at'] : null;
        $referrerId = is_int($referrer['id'] ?? null) ? $referrer['id'] : 0;
        $view = View::make(ReferralsScreen::INVITEE . ' زیرمجموعه ' . Words::name($user), ...ReferralsScreen::under(Fmt::bare(ReferralsScreen::INVITEES)))
            ->card([
                'زیرمجموعه' => Words::account($user) . Words::banned($row['status'] ?? null),
                'معرف' => Words::account($referrer),
                'پورسانت برای معرف' => (float) $earned > 0 ? Fmt::money($earned) : Fmt::NONE,
                'عضویت' => Fmt::date($joined) . ' (' . Words::ago($joined) . ')',
            ]);

        return $view->grid(
            1,
            Links::customer($admin, $user, 'صفحه زیرمجموعه'),
            Links::customer($admin, $referrer, 'صفحه معرف'),
            $referrerId > 0 ? Button::to(ReferralsScreen::INVITEE . ' زیرمجموعه‌های همین معرف', InviteesScreen::ID, 'ref', $referrerId) : null,
        )->back(InviteesScreen::ID);
    }
}
