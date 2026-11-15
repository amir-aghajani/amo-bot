<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Core;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;

/**
 * «🛡 مدیران ربات» (the owner's and an agent's, `bot_admins`): whether the shop's admins manage it from the bot, and the
 * grants beyond the daily work — the website's «مدیران سایت» list, word for word.
 */
final class BotAdminsScreen extends SettingsScreen
{
    public function __construct(private readonly BotSettingsScreen $settings) {}

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    protected function screen(): string
    {
        return 'ba';
    }

    protected function title(): string
    {
        return '🛡 مدیران ربات';
    }

    protected function path(): array
    {
        return ['مدیریت', 'تنظیمات'];
    }

    protected function parent(): string
    {
        return 'hc';
    }

    protected function intro(): string
    {
        return 'مدیران ربات همان مشتری‌هایی‌اند که در «کاربران» مدیر ربات شده‌اند. با «⚙️ مدیریت» در ربات، کارهای روزانه فروشگاه را انجام می‌دهند؛ هر کار بیشتر را این‌جا به آن‌ها بسپارید.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('bot_admins_enabled', 'کار مدیران از ربات', 'پرداخت‌ها، سفارش‌ها، سرویس‌ها، کاربران، تیکت‌ها، نظرها و ارسال‌های همگانی؛ و آنچه پایین‌تر سپرده می‌شود.'),
            FieldDef::toggle('bot_admins_grant_catalog', 'پلن‌ها و دسته‌بندی‌ها', 'ساختن، ویرایش، مرتب کردن، روشن و خاموش کردن و حذف پلن‌ها و دسته‌بندی‌ها؛ و گروه‌های مشتری و گذاشتن مشتری در گروه «همکار».')->when('bot_admins_enabled', [true]),
            FieldDef::toggle('bot_admins_grant_wallet', 'کیف پول مشتری', 'افزایش و کم کردن موجودی کیف پول مشتری.')->when('bot_admins_enabled', [true]),
            FieldDef::toggle('bot_admins_grant_refunds', 'بازپرداخت', 'بازپرداخت پرداخت مشتری.')->when('bot_admins_enabled', [true]),
            FieldDef::toggle('bot_admins_grant_payments', 'رد یکجای رسیدها', 'رد کردن یکجای رسیدهای در انتظار بررسی؛ رد کردن رسیدها یکی‌یکی همیشه ممکن است.')->when('bot_admins_enabled', [true]),
            FieldDef::toggle('bot_admins_grant_extend', 'افزایش زمان و حجم سرویس', 'دادن روز و حجم بیشتر به سرویس یک مشتری.')->when('bot_admins_enabled', [true]),
            FieldDef::toggle('bot_admins_grant_manual_orders', 'افزودن سفارش برای مشتری', 'ساختن سرویس برای مشتری به صورت رایگان، از کیف پولش یا با پولی که بیرون از فروشگاه گرفته شده.')->when('bot_admins_enabled', [true]),
            FieldDef::toggle('bot_admins_grant_delete', 'حذف یا انتقال سرویس', 'حذف همیشگی سرویس مشتری، یا انتقال آن به حساب مشتری دیگر.')->when('bot_admins_enabled', [true]),
            FieldDef::toggle('bot_admins_grant_account_security', 'امنیت حساب مشتری', 'خاموش کردن ورود دو مرحله‌ای حساب وب‌سایت مشتری و خارج کردنش از همه دستگاه‌ها.')->when('bot_admins_enabled', [true]),
        ];
    }

    protected function source(): SettingsSource
    {
        return new BotGroupSource('bot_admins', $this->settings);
    }
}
