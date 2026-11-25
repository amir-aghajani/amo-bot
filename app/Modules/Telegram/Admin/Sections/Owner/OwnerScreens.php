<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner;

use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgencyApproveScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgencyLevelFormScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgencyLevelScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgencyLevelsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgencyRenewScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgencyRequestScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgencyRequestsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgencyScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgencySettingsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgentHiddenScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgentScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgentsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgentTermsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Agency\AgentTrafficScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Payments\DepositSettingsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Payments\PaymentCardFormScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Payments\PaymentCardScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Payments\PaymentCardsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Payments\PaymentDriversScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Payments\PaymentMethodFormScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Payments\PaymentMethodScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Payments\PaymentMethodsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Servers\ServerDriversScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Servers\ServerFormScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Servers\ServerGrantFormScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Servers\ServerGrantsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Servers\ServerInboundsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Servers\ServerMigrationFailuresScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Servers\ServerMigrationFormScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Servers\ServerMigrationsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Servers\ServerScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Servers\ServersScreen;

/** The management screens of servers, the agency and payment methods: their ids (Telegram\Admin\Ui\Screens) and classes. */
final class OwnerScreens
{
    /**
     * @var array<string, class-string<\App\Modules\Telegram\Admin\Ui\Screen>>
     */
    public const SCREENS = [
        ServersScreen::ID => ServersScreen::class,
        ServerScreen::ID => ServerScreen::class,
        ServerDriversScreen::ID => ServerDriversScreen::class,
        ServerFormScreen::ID => ServerFormScreen::class,
        ServerInboundsScreen::ID => ServerInboundsScreen::class,
        ServerGrantsScreen::ID => ServerGrantsScreen::class,
        ServerGrantFormScreen::ID => ServerGrantFormScreen::class,
        ServerMigrationsScreen::ID => ServerMigrationsScreen::class,
        ServerMigrationFormScreen::ID => ServerMigrationFormScreen::class,
        ServerMigrationFailuresScreen::ID => ServerMigrationFailuresScreen::class,
        AgencyScreen::ID => AgencyScreen::class,
        AgencyRequestsScreen::ID => AgencyRequestsScreen::class,
        AgencyRequestScreen::ID => AgencyRequestScreen::class,
        AgencyApproveScreen::ID => AgencyApproveScreen::class,
        AgencyRenewScreen::ID => AgencyRenewScreen::class,
        AgentsScreen::ID => AgentsScreen::class,
        AgentScreen::ID => AgentScreen::class,
        AgentTermsScreen::ID => AgentTermsScreen::class,
        AgentTrafficScreen::ID => AgentTrafficScreen::class,
        AgentHiddenScreen::ID => AgentHiddenScreen::class,
        AgencyLevelsScreen::ID => AgencyLevelsScreen::class,
        AgencyLevelScreen::ID => AgencyLevelScreen::class,
        AgencyLevelFormScreen::ID => AgencyLevelFormScreen::class,
        AgencySettingsScreen::ID => AgencySettingsScreen::class,
        PaymentMethodsScreen::ID => PaymentMethodsScreen::class,
        PaymentMethodScreen::ID => PaymentMethodScreen::class,
        PaymentDriversScreen::ID => PaymentDriversScreen::class,
        PaymentMethodFormScreen::ID => PaymentMethodFormScreen::class,
        PaymentCardsScreen::ID => PaymentCardsScreen::class,
        PaymentCardScreen::ID => PaymentCardScreen::class,
        PaymentCardFormScreen::ID => PaymentCardFormScreen::class,
        DepositSettingsScreen::ID => DepositSettingsScreen::class,
    ];
}
