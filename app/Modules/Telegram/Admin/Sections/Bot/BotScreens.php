<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot;

use App\Modules\Telegram\Admin\Sections\Bot\Broadcasts\BroadcastScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Broadcasts\BroadcastsScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Channels\ChannelScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Channels\ChannelsScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Gifts\MassGiftScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Gifts\MassGiftsScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Gifts\MassGiftWizard;
use App\Modules\Telegram\Admin\Sections\Bot\Keyboards\KeyboardButtonScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Keyboards\KeyboardScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Keyboards\KeyboardsScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\AutoRenewCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ClientNamesCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ExpiredServicesCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ExtrasCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\GeneralCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\PaymentsCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\QrCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ReferralCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\RemindersCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\RenewalCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ReportTopicsCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ServiceClaimsCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ServiceTransfersCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\WalletCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\WalletTransfersCard;
use App\Modules\Telegram\Admin\Sections\Bot\Texts\PremiumEmojisScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Texts\TextGroupScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Texts\TextScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Texts\TextsScreen;
use App\Modules\Telegram\Admin\Ui\Words;

/** The management screens of keyboards, bot texts, broadcasts, mass gifts and the bot settings cards: their ids (Telegram\Admin\Ui\Screens) and classes. */
final class BotScreens
{
    /** The sections the bot's screens are in: «مدیریت › ربات» (the «🤖 ربات» menu, `hb`). */
    public const PATH = [Words::ROOT, 'ربات'];

    /** The «🤖 ربات» menu (Ui\Screens::hub()), where the bot's screens go back to. */
    public const MENU = 'hb';

    /**
     * @var array<string, class-string<\App\Modules\Telegram\Admin\Ui\Screen>>
     */
    public const SCREENS = [
        BroadcastsScreen::ID => BroadcastsScreen::class,
        BroadcastScreen::ID => BroadcastScreen::class,
        MassGiftsScreen::ID => MassGiftsScreen::class,
        MassGiftScreen::ID => MassGiftScreen::class,
        MassGiftWizard::ID => MassGiftWizard::class,
        TextsScreen::ID => TextsScreen::class,
        TextGroupScreen::ID => TextGroupScreen::class,
        TextScreen::ID => TextScreen::class,
        PremiumEmojisScreen::ID => PremiumEmojisScreen::class,
        KeyboardsScreen::ID => KeyboardsScreen::class,
        KeyboardScreen::ID => KeyboardScreen::class,
        KeyboardButtonScreen::ID => KeyboardButtonScreen::class,
        ChannelsScreen::ID => ChannelsScreen::class,
        ChannelScreen::ID => ChannelScreen::class,
        ReportGroupScreen::ID => ReportGroupScreen::class,
        ReportTopicsCard::ID => ReportTopicsCard::class,
        BotSettingsMenu::ID => BotSettingsMenu::class,
        GeneralCard::ID => GeneralCard::class,
        ClientNamesCard::ID => ClientNamesCard::class,
        WalletCard::ID => WalletCard::class,
        WalletTransfersCard::ID => WalletTransfersCard::class,
        PaymentsCard::ID => PaymentsCard::class,
        RenewalCard::ID => RenewalCard::class,
        AutoRenewCard::ID => AutoRenewCard::class,
        ExtrasCard::ID => ExtrasCard::class,
        RemindersCard::ID => RemindersCard::class,
        ExpiredServicesCard::ID => ExpiredServicesCard::class,
        ServiceClaimsCard::ID => ServiceClaimsCard::class,
        ServiceTransfersCard::ID => ServiceTransfersCard::class,
        ReferralCard::ID => ReferralCard::class,
        QrCard::ID => QrCard::class,
    ];
}
