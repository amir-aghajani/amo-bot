<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One invitee (a row of InviteesScreen's page): who, who brought them, what their payments earned that one and when
 * they came — and their pages, and the others the same referrer brought.
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
        $earned = is_numeric($row['earned'] ?? null) ? (string) $row['earned'] : '0';
        $joined = is_string($row['joined_at'] ?? null) ? $row['joined_at'] : null;
        $view = View::make('🙋 زیرمجموعه ' . Words::name($user), 'مدیریت', 'فروش', 'زیرمجموعه‌گیری', 'زیرمجموعه‌ها')
            ->facts([
                'زیرمجموعه' => Words::account($user) . Words::banned($row['status'] ?? null),
                'معرف' => Words::account($referrer),
                'پورسانت برای معرف' => (float) $earned > 0 ? Fmt::money($earned) : Fmt::NONE,
                'عضویت' => Fmt::date($joined) . ' (' . Words::ago($joined) . ')',
            ]);
        $referrerId = is_int($referrer['id'] ?? null) ? $referrer['id'] : 0;

        return $view->grid(
            1,
            Links::customer($admin, $user),
            Links::customer($admin, $referrer, 'معرف: ' . Words::name($referrer)),
            $referrerId > 0 ? Button::to('🙋 زیرمجموعه‌های همین معرف', InviteesScreen::ID, 'ref', $referrerId) : null,
        )->back(InviteesScreen::ID);
    }
}
