<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Core;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🛡 مدیران ربات» (the owner's and an agent's, `bot_admins`): whether the shop's admins manage it from the bot, and the
 * grants beyond the daily work — the website's «مدیران سایت» list, word for word —, a part of their own («کارهای
 * بیشتر»). The grants are set whether or not the admins are let in yet, as the panel sets them: ready before the switch
 * is turned on.
 */
final class BotAdminsScreen extends SettingsScreen
{
    public const ID = 'ba';

    public const TITLE = Icons::BOT_ADMINS . ' مدیران ربات';

    /** Each grant's switch, its label and what it lets the admins do, in the grants' order. */
    private const GRANTS = [
        'bot_admins_grant_catalog' => ['پلن‌ها و دسته‌بندی‌ها', 'ساختن، ویرایش، مرتب کردن، روشن و خاموش کردن و حذف پلن‌ها و دسته‌بندی‌ها؛ و گروه‌های مشتری و گذاشتن مشتری در گروه «همکار».'],
        'bot_admins_grant_wallet' => ['کیف پول مشتری', 'افزایش و کم کردن موجودی کیف پول مشتری.'],
        'bot_admins_grant_refunds' => ['بازپرداخت', 'بازپرداخت پرداخت مشتری.'],
        'bot_admins_grant_payments' => ['رد یکجای رسیدها', 'رد کردن یکجای رسیدهای در انتظار بررسی؛ رد کردن رسیدها یکی‌یکی همیشه ممکن است.'],
        'bot_admins_grant_extend' => ['افزایش زمان و حجم سرویس', 'دادن روز و حجم بیشتر به سرویس یک مشتری.'],
        'bot_admins_grant_manual_orders' => ['افزودن سفارش برای مشتری', 'ساختن سرویس برای مشتری به صورت رایگان، از کیف پولش یا با پولی که بیرون از فروشگاه گرفته شده.'],
        'bot_admins_grant_delete' => ['حذف یا انتقال سرویس', 'حذف همیشگی سرویس مشتری، یا انتقال آن به حساب مشتری دیگر.'],
        'bot_admins_grant_account_security' => ['امنیت حساب مشتری', 'خاموش کردن ورود دو مرحله‌ای حساب وب‌سایت مشتری و خارج کردنش از همه دستگاه‌ها.'],
    ];

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
        return self::TITLE;
    }

    protected function path(): array
    {
        return [Words::ROOT, Fmt::bare(Words::SETTINGS)];
    }

    protected function parent(): string
    {
        return 'hc';
    }

    protected function intro(): string
    {
        return 'مدیران ربات همان مشتری‌هایی‌اند که در «کاربران» مدیر ربات شده‌اند. با «⚙️ مدیریت» در ربات، کارهای روزانه فروشگاه را انجام می‌دهند؛ هر کار بیشتر را این‌جا به آن‌ها بسپارید، حتی پیش از روشن کردن «کار مدیران از ربات».';
    }

    protected function fields(): array
    {
        $fields = [FieldDef::toggle('bot_admins_enabled', 'کار مدیران از ربات', 'پرداخت‌ها، سفارش‌ها، سرویس‌ها، کاربران، تیکت‌ها، نظرها و ارسال‌های همگانی؛ و آنچه در «کارهای بیشتر» سپرده می‌شود.')];
        foreach (self::GRANTS as $name => [$label, $hint]) {
            $fields[] = FieldDef::toggle($name, $label, $hint);
        }

        return $fields;
    }

    /** The grants, a part of their own (the panel's «کارهای بیشتر»). */
    protected function parts(): array
    {
        return [['➕ کارهای بیشتر', array_keys(self::GRANTS)]];
    }

    protected function source(): SettingsSource
    {
        return new BotGroupSource('bot_admins', $this->settings);
    }
}
