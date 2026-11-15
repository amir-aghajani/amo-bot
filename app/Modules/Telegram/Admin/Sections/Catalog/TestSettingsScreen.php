<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;

/**
 * «قوانین اکانت تست» (the bot settings' `test_accounts` group, the tests page's settings section): the switch, the quota
 * — tests a customer, over how many days, the shop's cap a day, newcomers only — and the clean-up of the tests that
 * ended or were never connected, its numbers shown while it is on.
 */
final class TestSettingsScreen extends SettingsScreen
{
    public const ID = 'tss';

    public function __construct(private readonly BotSettingsScreen $settings) {}

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '⚙️ قوانین اکانت تست';
    }

    protected function path(): array
    {
        return ['مدیریت', 'فروشگاه', 'اکانت تست'];
    }

    protected function parent(): string
    {
        return TestsScreen::ID;
    }

    protected function intro(): string
    {
        return 'هر مشتری چند اکانت تست می‌گیرد و اکانت‌های تمام‌شده کی پاک می‌شوند. حجم و مدت هر اکانت تست را هر سرور خودش دارد. مدیرهای ربات سهمیه ندارند و هر چند بار که بخواهند اکانت تست می‌گیرند.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('test_enabled', 'اکانت تست فعال باشد', 'روشن که باشد، مشتری در ربات با دکمه «اکانت تست» روی یکی از سرورهایی که اکانت تست می‌دهند یک اکانت رایگان می‌گیرد. خاموش، دکمه از منوی ربات برداشته می‌شود و اکانت‌های قبلی سر جایشان می‌مانند.'),
            FieldDef::number('test_per_user', 'تعداد اکانت تست هر مشتری', '', 'بین ۱ تا ۱۰۰'),
            FieldDef::number('test_period_days', 'دوره سهمیه', 'روز', 'هر مشتری در هر چند روز همین تعداد اکانت تست می‌گیرد؛ ۰ یعنی برای همیشه: هر مشتری روی هم همین تعداد می‌گیرد.'),
            FieldDef::number('test_daily_limit', 'سقف روزانه فروشگاه', '', 'روزی حداکثر چند اکانت تست، برای همه مشتری‌ها روی هم؛ ۰ یعنی بدون سقف.'),
            FieldDef::toggle('test_newcomers_only', 'فقط مشتری‌هایی که خرید نکرده‌اند', 'روشن که باشد، مشتری‌ای که سرویس پولی خریده دیگر اکانت تست نمی‌گیرد.'),
            FieldDef::toggle('test_cleanup', 'اکانت‌های تمام‌شده حذف شوند', 'روشن که باشد، اکانت تستی که زمان یا حجمش تمام شده، بعد از فاصله حذف خودش حذف می‌شود. اکانت تست حذف‌شده از پنل و فروشگاه پاک می‌شود، ولی در سهمیه مشتری حساب می‌ماند.'),
            FieldDef::number('test_cleanup_hours', 'فاصله حذف', 'ساعت', 'بین ۰ تا ۷۲۰ ساعت؛ ۰ یعنی همان وقت که تمام شد.')->when('test_cleanup', [true]),
            FieldDef::number('test_unused_days', 'حذف اکانت وصل‌نشده', 'روز', 'اکانتی که این چند روز بعد از ساخت هنوز وصل نشده حذف می‌شود؛ ۰ یعنی هیچ‌وقت: اکانتی که از اولین اتصال شروع می‌شود تا وصل شدن می‌ماند.')->when('test_cleanup', [true]),
        ];
    }

    protected function source(): SettingsSource
    {
        return new BotGroupSource('test_accounts', $this->settings);
    }
}
