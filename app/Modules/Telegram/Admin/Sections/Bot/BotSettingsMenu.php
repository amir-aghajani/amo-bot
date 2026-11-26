<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot;

use App\Modules\Telegram\Admin\Sections\Bot\Settings\ClientNamesCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ExpiredServicesCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ExtrasCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\GeneralCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\PaymentsCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\QrCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\RemindersCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\RenewalCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ServiceClaimsCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ServiceTransfersCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\WalletCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\WalletTransfersCard;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🤖 تنظیمات ربات» (the owner's and an agent's): the bot's own cards, each its own screen (Settings\*) under its title,
 * two a row and grouped as the owner looks for them — the bot itself, the wallet and the card payment, a service's life.
 * A card of a feature lives with that feature (the channels and the report group in «🤖 ربات», the referral rules in
 * «🔗 زیرمجموعه‌گیری», the tests', the custom services' and the rewards' with them, «🛡 مدیران ربات» in «⚙️ تنظیمات»),
 * which the folded help says. An entry the seat may not open (the claims: the main shop's) is left out. The bot switched
 * off is said first, as the panel says it over every section.
 */
final class BotSettingsMenu extends BaseScreen
{
    public const ID = 'bs';

    /** The menu's name, as the cards' path says it too. */
    public const TITLE = 'تنظیمات ربات';

    /** The cards, in rows of two: the bot itself, the money, a service's life. */
    private const CARDS = [
        [GeneralCard::ID, GeneralCard::TITLE],
        [ClientNamesCard::ID, ClientNamesCard::TITLE],
        [WalletCard::ID, WalletCard::TITLE],
        [WalletTransfersCard::ID, WalletTransfersCard::TITLE],
        [PaymentsCard::ID, PaymentsCard::TITLE],
        [QrCard::ID, QrCard::TITLE],
        [RenewalCard::ID, RenewalCard::TITLE],
        [ExtrasCard::ID, ExtrasCard::TITLE],
        [RemindersCard::ID, RemindersCard::TITLE],
        [ExpiredServicesCard::ID, ExpiredServicesCard::TITLE],
        [ServiceTransfersCard::ID, ServiceTransfersCard::TITLE],
        [ServiceClaimsCard::ID, ServiceClaimsCard::TITLE],
    ];

    public function show(Admin $admin, array $args): View
    {
        $view = View::make(Icons::BOT . ' ' . self::TITLE, Words::ROOT, Fmt::bare(Words::SETTINGS))
            ->line('رفتار ربات برای مشتری‌ها؛ هر تغییر همان لحظه روی ربات اعمال می‌شود.');
        if ((BotGroupSource::settings($admin)['enabled'] ?? true) === false) {
            $view->line()->line('⚠️ <b>ربات خاموش است.</b> مشتری‌ها به جای منو فقط پیام «ربات فعلا غیرفعال است» می‌بینند. مدیریت فروشگاه (مالک، نماینده و مدیران ربات) همچنان از ربات استفاده می‌کند.');
        }
        $buttons = [];
        foreach (self::CARDS as [$id, $label]) {
            $buttons[] = $admin->opens($id) ? Button::to($label, $id) : null;
        }

        return $view->grid(2, ...$buttons)
            ->help('تنظیمات هر بخش کنار خود آن بخش است: کانال‌های اجباری و گروه گزارش‌ها در «' . Fmt::e(Icons::BOT . ' ربات') . '»، قوانین زیرمجموعه‌گیری در «' . Fmt::e(Icons::REFERRALS . ' زیرمجموعه‌گیری') . '»، تنظیمات اکانت تست، سرویس دلخواه، کدهای تخفیف و هدیه، کش‌بک، گردونه و قرعه‌کشی در صفحه خودشان، و «' . Fmt::e(Icons::BOT_ADMINS . ' مدیران ربات') . '» در «' . Fmt::e(Words::SETTINGS) . '». هر تنظیمی را با «' . Fmt::e(Icons::SEARCH . ' جستجوی تنظیمات') . '» هم پیدا کنید.')
            ->back('hc');
    }
}
