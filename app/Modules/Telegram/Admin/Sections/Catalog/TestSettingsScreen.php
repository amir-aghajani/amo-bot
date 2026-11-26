<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;

/**
 * «قوانین اکانت تست» (the bot settings' `test_accounts` group, the tests page's settings section, reached from it alone):
 * the switch on the card, and the panel's two parts each a screen of its own — the quota (tests a customer, over how
 * many days, the shop's cap a day, newcomers only) and the clean-up of the tests that ended or were never connected, its
 * numbers shown while it is on.
 */
final class TestSettingsScreen extends SettingsScreen
{
    public const ID = 'tss';

    public const TITLE = Icons::SETTINGS . ' قوانین اکانت تست';

    public function __construct(private readonly BotSettingsScreen $settings) {}

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
        return CatalogFmt::under(Fmt::bare(TestsScreen::TITLE));
    }

    protected function parent(): string
    {
        return TestsScreen::ID;
    }

    protected function intro(): string
    {
        return 'هر مشتری چند اکانت تست می‌گیرد و اکانت‌های تمام‌شده کی پاک می‌شوند. حجم و مدت هر اکانت تست را هر سرور خودش دارد. مدیرهای ربات سهمیه ندارند و هر چند بار که بخواهند اکانت تست می‌گیرند.';
    }

    /** Field places are its buttons' data (`e:<n>`): a new field goes last. */
    protected function fields(): array
    {
        return [
            FieldDef::toggle('test_enabled', 'اکانت تست فعال باشد', 'روشن که باشد، مشتری در ربات با دکمه «اکانت تست» روی یکی از سرورهایی که اکانت تست می‌دهند یک اکانت رایگان می‌گیرد. خاموش، دکمه از منوی ربات برداشته می‌شود و اکانت‌های قبلی سر جایشان می‌مانند.'),
            FieldDef::number('test_per_user', 'تعداد اکانت تست هر مشتری', '', 'هر مشتری در هر دوره سهمیه همین تعداد اکانت تست می‌گیرد.')->limits('از ۱ تا ۱۰۰')->example('۱'),
            FieldDef::number('test_period_days', 'دوره سهمیه', 'روز', 'هر مشتری در هر چند روز همین تعداد اکانت تست می‌گیرد؛ ۰ یعنی برای همیشه: هر مشتری روی هم همین تعداد می‌گیرد.')->example('۳۰'),
            FieldDef::number('test_daily_limit', 'سقف روزانه فروشگاه', '', 'روزی حداکثر چند اکانت تست، برای همه مشتری‌ها روی هم؛ ۰ یعنی بدون سقف.')->example('۵۰'),
            FieldDef::toggle('test_newcomers_only', 'فقط مشتری‌هایی که خرید نکرده‌اند', 'روشن که باشد، مشتری‌ای که سرویس پولی خریده دیگر اکانت تست نمی‌گیرد.'),
            FieldDef::toggle('test_cleanup', 'اکانت‌های تمام‌شده حذف شوند', 'روشن که باشد، اکانت تستی که زمان یا حجمش تمام شده، بعد از فاصله حذف خودش حذف می‌شود. اکانت تست حذف‌شده از پنل و فروشگاه پاک می‌شود، ولی در سهمیه مشتری حساب می‌ماند.'),
            FieldDef::number('test_cleanup_hours', 'فاصله حذف', 'ساعت', '۰ یعنی همان وقت که تمام شد.')->limits('از ۰ تا ۷۲۰ ساعت')->example('۲۴')->when('test_cleanup', [true]),
            FieldDef::number('test_unused_days', 'حذف اکانت وصل‌نشده', 'روز', 'اکانتی که این چند روز بعد از ساخت هنوز وصل نشده حذف می‌شود؛ ۰ یعنی هیچ‌وقت: اکانتی که از اولین اتصال شروع می‌شود تا وصل شدن می‌ماند.')->example('۷')->when('test_cleanup', [true]),
        ];
    }

    /** The panel's parts of the card: the quota, and the clean-up. */
    protected function parts(): array
    {
        return [
            ['🔢 سهمیه', ['test_per_user', 'test_period_days', 'test_daily_limit', 'test_newcomers_only']],
            [Icons::EXPIRED_CLEANUP . ' حذف خودکار', ['test_cleanup', 'test_cleanup_hours', 'test_unused_days']],
        ];
    }

    protected function source(): SettingsSource
    {
        return new BotGroupSource('test_accounts', $this->settings);
    }
}
