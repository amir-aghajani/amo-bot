<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * One alert on a referrer (a row of ReferralAlertsScreen's page): on whom, why and when, in one card — and
 * «✅ رفع هشدار», asked first in the panel's words: the referrer earns gifts again from then on, what was held stays
 * held.
 *
 * Actions: `clr` (id: asks), `clrd` (id: clears).
 */
final class ReferralAlertScreen extends KeptRowScreen
{
    public const ID = 'rfav';

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'clr':
                $row = ListScreen::keptRow($admin, ReferralAlertsScreen::ID, $id);
                if ($row === null) {
                    return $admin->open(ReferralAlertsScreen::ID);
                }

                return $admin->confirm(
                    'هشدار رفع شود؟',
                    Button::to(UiWords::yes('رفع شود'), self::ID, 'clrd', $id),
                    Button::to(UiWords::CANCEL, self::ID, Nav::SHOW, $id),
                    Words::person(is_array($row['referrer'] ?? null) ? $row['referrer'] : null) . "\n" . 'از این پس هدیه شروع و جایزه دعوت دوباره به این معرف داده می‌شود؛ آنچه تا حالا نگه داشته شد داده نمی‌شود. اگر هشدار دیگری هم روی او باشد، تا رفع آن هم چیزی داده نمی‌شود.',
                    danger: false,
                );
            case 'clrd':
                $admin->api->post("/referrals/alerts/{$id}/clear");
                $rows = (array) ($admin->state(ReferralAlertsScreen::ID)['rows'] ?? []);
                unset($rows[$id]);
                $admin->remember(ReferralAlertsScreen::ID, ['rows' => $rows]);
                $admin->notice('✅ هشدار رفع شد');

                return $admin->open(ReferralAlertsScreen::ID);
        }

        return $this->show($admin, $args);
    }

    protected function list(): string
    {
        return ReferralAlertsScreen::ID;
    }

    protected function view(Admin $admin, array $row): View
    {
        $id = (int) ($row['id'] ?? 0);
        $referrer = is_array($row['referrer'] ?? null) ? $row['referrer'] : null;
        $created = is_string($row['created_at'] ?? null) ? $row['created_at'] : null;
        $view = View::make(ReferralsScreen::ALERT . ' هشدار ' . Fmt::id($id), ...ReferralsScreen::under(Fmt::bare(ReferralsScreen::ALERTS)))
            ->card([
                'معرف' => Words::account($referrer) . Words::banned($row['status'] ?? null),
                'دلیل' => Fmt::e(ReferralAlertsScreen::reason($row)),
                'زمان' => Fmt::date($created, true) . ' (' . Words::ago($created) . ')',
            ])
            ->help(Fmt::e(ReferralAlertsScreen::HELD));

        return $view->row(Button::to('✅ رفع هشدار', self::ID, 'clr', $id)->success())
            ->row(Links::customer($admin, $referrer, 'صفحه معرف'))
            ->back(ReferralAlertsScreen::ID);
    }
}
