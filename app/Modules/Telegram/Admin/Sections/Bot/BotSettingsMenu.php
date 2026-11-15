<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\Origin;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🤖 تنظیمات ربات» (the owner's and an agent's): every card of the panel's page, in its order — each its own screen
 * (Settings\*), the channels, the report group and «🛡 مدیران ربات» the sections that have them —, then the cards of the
 * sections that keep theirs on their own pages (discount codes, gift codes, the wheel, cashback, the draw, tests, custom
 * services). Each entry carries this menu as its origin (Origin), so its «⬅️ بازگشت» comes back here. An entry whose
 * screen is not there, or that the seat may not open (the claims and custom services: the main shop's), is left out.
 * The bot switched off is said first, as the panel says it over every section.
 */
final class BotSettingsMenu extends BaseScreen
{
    public const ID = 'bs';

    /** The page's sections, in its order: [screen, label]. */
    private const CARDS = [
        ['bsg', '⚙️ عمومی'],
        ['bsn', '🪪 نام کاربری سرویس'],
        ['ch', '📢 کانال‌ها'],
        ['bsw', '👛 کیف پول'],
        ['bst', '💸 انتقال موجودی'],
        ['bsp', '💳 پرداخت کارت به کارت'],
        ['bsr', '🔁 تمدید سرویس'],
        ['bsx', '➕ خرید اضافه'],
        ['bsm', '⏰ یادآوری'],
        ['bse', '🗑 سرویس‌های منقضی‌شده'],
        ['bsc', '🔗 افزودن سرویس با لینک'],
        ['bsv', '🔀 انتقال سرویس'],
        ['bsf', '🤝 زیرمجموعه‌گیری'],
        ['rg', '📋 گروه گزارش‌ها'],
        ['bsq', "🔳 کد \u{2068}QR\u{2069}"],
        ['ba', '🛡 مدیران ربات'],
    ];

    /** The cards other sections keep on their own pages: [screen, label]. */
    private const ELSEWHERE = [
        ['dcs', '🏷 کدهای تخفیف'],
        ['gcs', '🎁 کدهای هدیه'],
        ['whs', '🎡 گردونه شانس'],
        ['cbs', '💰 کش‌بک'],
        ['lts', '🎲 قرعه‌کشی'],
        ['tss', '🧪 اکانت تست'],
        ['css', '🛠 سرویس دلخواه'],
    ];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $settings = BotGroupSource::settings($admin);
        $view = View::make('🤖 تنظیمات ربات', 'مدیریت', 'تنظیمات')
            ->line('رفتار ربات برای مشتری‌ها. تغییرات همان لحظه روی ربات اعمال می‌شود؛ نیازی به اجرای دوباره نیست.');
        if (($settings['enabled'] ?? true) === false) {
            $view->line()->line('⚠️ <b>ربات خاموش است.</b> مشتری‌ها به جای منو فقط پیام «ربات فعلا غیرفعال است» می‌بینند. مدیریت فروشگاه (مالک، نماینده و مدیران ربات) همچنان از ربات استفاده می‌کند.');
        }
        $view->grid(2, ...self::buttons($admin, self::CARDS));
        $elsewhere = self::buttons($admin, self::ELSEWHERE);
        if ($elsewhere !== []) {
            $view->line()->line('<i>ردیف‌های آخر، از «' . Fmt::e($elsewhere[0]->label) . '» به بعد: تنظیمات بخش‌هایی که در پنل صفحه خودشان را دارند.</i>');
            $view->grid(2, ...$elsewhere);
        }

        return $view->back('hc');
    }

    /**
     * The entries the seat may open, as buttons that come back here.
     *
     * @param list<array{0: string, 1: string}> $entries
     * @return list<Button>
     */
    private static function buttons(Admin $admin, array $entries): array
    {
        $buttons = [];
        foreach ($entries as [$id, $label]) {
            if ($admin->opens($id)) {
                $buttons[] = Origin::button($label, $id, self::ID);
            }
        }

        return $buttons;
    }
}
