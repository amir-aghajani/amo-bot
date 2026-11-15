<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

/** The management screens of the dashboard, support tickets, reviews and referrals: their ids (Telegram\Admin\Ui\Screens) and classes. */
final class SupportScreens
{
    /**
     * @var array<string, class-string<\App\Modules\Telegram\Admin\Ui\Screen>>
     */
    public const SCREENS = [
        DashboardScreen::ID => DashboardScreen::class,
        DashboardChartScreen::ID => DashboardChartScreen::class,
        DashboardRecentScreen::ID => DashboardRecentScreen::class,
        TicketsScreen::ID => TicketsScreen::class,
        TicketScreen::ID => TicketScreen::class,
        ReviewsScreen::ID => ReviewsScreen::class,
        ReviewScreen::ID => ReviewScreen::class,
        ReferralsScreen::ID => ReferralsScreen::class,
        ReferrersScreen::ID => ReferrersScreen::class,
        ReferrerScreen::ID => ReferrerScreen::class,
        InviteesScreen::ID => InviteesScreen::class,
        InviteeScreen::ID => InviteeScreen::class,
        CommissionsScreen::ID => CommissionsScreen::class,
        CommissionScreen::ID => CommissionScreen::class,
        ReferralAlertsScreen::ID => ReferralAlertsScreen::class,
        ReferralAlertScreen::ID => ReferralAlertScreen::class,
    ];
}
