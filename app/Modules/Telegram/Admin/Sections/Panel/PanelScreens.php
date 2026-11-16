<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel;

/**
 * The management screens of the website, the mini app, the panel's own settings (config.php, update, schedule, backups,
 * imports) and the settings search over all of them: their ids (Telegram\Admin\Ui\Screens) and classes.
 */
final class PanelScreens
{
    /**
     * @var array<string, class-string<\App\Modules\Telegram\Admin\Ui\Screen>>
     */
    public const SCREENS = [
        Website\WebsiteScreen::ID => Website\WebsiteScreen::class,
        Website\ConnectionScreen::ID => Website\ConnectionScreen::class,
        Website\TelegramLoginScreen::ID => Website\TelegramLoginScreen::class,
        Website\GoogleLoginScreen::ID => Website\GoogleLoginScreen::class,
        Website\EmailScreen::ID => Website\EmailScreen::class,
        Website\CaptchaScreen::ID => Website\CaptchaScreen::class,
        Website\PhoneLoginScreen::ID => Website\PhoneLoginScreen::class,
        Website\WebsiteReviewsScreen::ID => Website\WebsiteReviewsScreen::class,
        Website\StaffScreen::ID => Website\StaffScreen::class,
        MiniApp\MiniAppScreen::ID => MiniApp\MiniAppScreen::class,
        MiniApp\MiniAppConnectionScreen::ID => MiniApp\MiniAppConnectionScreen::class,
        MiniApp\MiniAppLookScreen::ID => MiniApp\MiniAppLookScreen::class,
        MiniApp\LogoScreen::ID => MiniApp\LogoScreen::class,
        MiniApp\PreviewScreen::ID => MiniApp\PreviewScreen::class,
        PanelSettingsScreen::ID => PanelSettingsScreen::class,
        Config\TelegramScreen::ID => Config\TelegramScreen::class,
        Config\DatabaseScreen::ID => Config\DatabaseScreen::class,
        Config\AppScreen::ID => Config\AppScreen::class,
        Config\MailScreen::ID => Config\MailScreen::class,
        Config\SmsScreen::ID => Config\SmsScreen::class,
        Config\AdvancedScreen::ID => Config\AdvancedScreen::class,
        LoginScreen::ID => LoginScreen::class,
        UpdateScreen::ID => UpdateScreen::class,
        Schedule\ScheduleScreen::ID => Schedule\ScheduleScreen::class,
        Schedule\ScheduleTaskScreen::ID => Schedule\ScheduleTaskScreen::class,
        Backups\BackupsScreen::ID => Backups\BackupsScreen::class,
        Backups\RestoreScreen::ID => Backups\RestoreScreen::class,
        Imports\ImportsScreen::ID => Imports\ImportsScreen::class,
        Imports\ImportStartScreen::ID => Imports\ImportStartScreen::class,
        Imports\ImportDatabaseScreen::ID => Imports\ImportDatabaseScreen::class,
        Imports\ImportPanelsScreen::ID => Imports\ImportPanelsScreen::class,
        Imports\ImportChoicesScreen::ID => Imports\ImportChoicesScreen::class,
        Imports\ImportReportScreen::ID => Imports\ImportReportScreen::class,
        Imports\ImportProblemsScreen::ID => Imports\ImportProblemsScreen::class,
        SystemScreen::ID => SystemScreen::class,
        Search\SettingsSearchScreen::ID => Search\SettingsSearchScreen::class,
    ];
}
