<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\PutSource;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;

/**
 * «⚙️ تنظیمات نمایندگی» (the panel's «برنامه نمایندگی» card): whether the program takes requests, what an approval
 * gives — the starting credit, the agency's length, the monthly minimum —, the traffic agents buy (the least and the
 * amounts offered), and how many days ahead an agent is warned before their selling stops. Saved whole (PUT
 * /agency/settings) a field at a time.
 */
final class AgencySettingsScreen extends SettingsScreen
{
    public const ID = 'ags';

    public const TITLE = Icons::SETTINGS . ' تنظیمات نمایندگی';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function path(): array
    {
        return AgencyScreen::path();
    }

    protected function parent(): string
    {
        return AgencyScreen::ID;
    }

    protected function intro(): string
    {
        return 'مشتری از صفحه «نمایندگی» ربات درخواست می‌دهد و پشتیبانی آن را با انتخاب یک سطح تایید یا رد می‌کند. نماینده ربات فروش خودش را دارد و حجمی را که رباتش می‌فروشد با قیمت هر گیگابایت سطحش از ربات اصلی می‌خرد.'
            . "\n\n" . 'اعتبار اولیه، مدت نمایندگی و کف خرید ماهانه برای تاییدی است که از دکمه‌های گروه گزارش‌ها انجام می‌شود؛ هنگام تایید هر درخواست می‌شود مقدار دیگری داد، و بعدا از «سطح، اعتبار و شرایط فروش» هر نماینده.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('enabled', 'نمایندگی فعال باشد', 'خاموش که باشد، درخواست تازه‌ای ثبت نمی‌شود و دکمه «نمایندگی» برای مشتری‌ها از منوی ربات برداشته می‌شود. نماینده‌ها همچنان حسابشان را می‌بینند و رباتشان کار می‌کند.'),
            FieldDef::money('default_credit', 'اعتبار اولیه نماینده', 'موجودی نماینده تا این اندازه می‌تواند منفی شود. بدون اعتبار: نماینده فقط با موجودی کیف پولش می‌خرد.'),
            FieldDef::number('traffic_min', 'کمترین خرید حجم', 'گیگابایت', 'کمترین حجمی که نماینده می‌تواند بنویسد.'),
            FieldDef::list('traffic_presets', 'حجم‌های پیشنهادی', 'گیگابایت', 'دکمه‌هایی که نماینده در «خرید حجم» می‌بیند؛ قیمت هر کدام از سطح خود نماینده حساب می‌شود.'),
            FieldDef::number('term_days', 'مدت نمایندگی', 'روز', 'از روز تایید؛ بعد از آن فروش ربات نماینده متوقف می‌شود تا تمدیدش کنید. ۰: بدون پایان.'),
            FieldDef::money('monthly_minimum', 'کف خرید ماهانه', 'خرید حجم در هر ماه شمسی. ۰: بدون کف خرید.'),
            FieldDef::number('warn_days', 'هشدار پیش از توقف', 'روز', 'چند روز پیش از پایان نمایندگی، یا پایان ماهی که به کف نرسیده، به نماینده خبر داده شود.'),
        ];
    }

    protected function source(): SettingsSource
    {
        return new PutSource('/agency/settings', 'settings');
    }

    /** A starting credit, a length or a monthly minimum of 0 is none, said as an agent's terms say it. */
    protected function shows(FieldDef $field, mixed $value): ?string
    {
        $none = ['default_credit' => TermsScreen::NO_CREDIT, 'term_days' => AgencyEnd::NONE, 'monthly_minimum' => TermsScreen::NO_MINIMUM][$field->name] ?? null;

        return $none !== null && is_numeric($value) && (float) $value === 0.0 ? $none : null;
    }
}
