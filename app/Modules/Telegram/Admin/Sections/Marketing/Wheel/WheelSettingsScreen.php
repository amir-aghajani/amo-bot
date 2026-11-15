<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Wheel;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Links;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «⚙️ تنظیمات گردونه» (the panel's «قوانین گردونه», `wheel`): the switch, the wait between spins and a spin's cost — held
 * while the wheel is off, as the panel holds them —, and whether only buyers spin; with the panel's warning when it is on
 * and the bot's menu has no button for it.
 */
final class WheelSettingsScreen extends SettingsScreen
{
    public const ID = 'whs';

    public function __construct(
        private readonly BotSettingsScreen $settings,
    ) {}

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
        return '⚙️ تنظیمات گردونه';
    }

    protected function path(): array
    {
        return [...Words::PATH, 'گردونه شانس'];
    }

    protected function parent(): string
    {
        return WheelPrizesScreen::ID;
    }

    protected function intro(): string
    {
        return 'مشتری هر چند ساعت یک بار گردونه را می‌چرخاند و یکی از جایزه‌ها را می‌برد؛ چرخشی که جایزه‌اش داده نشود حساب نمی‌شود.' . "\n"
            . '• روشن که باشد، دکمه «گردونه شانس» در منوی ربات می‌آید. خاموش، دکمه از منو برداشته می‌شود و جایزه‌ها و چرخش‌های قبلی سر جایشان می‌مانند؛ فاصله و هزینه هم تا روشن شدن همان‌طور می‌مانند.' . "\n"
            . '• «فقط مشتری‌هایی که خرید کرده‌اند»: فقط مشتری‌ای که دست‌کم یک سرویس خریده گردونه را می‌چرخاند؛ مدیرهای ربات همیشه می‌توانند.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('wheel_enabled', 'گردونه شانس فعال باشد'),
            FieldDef::number('wheel_cooldown_hours', 'فاصله دو چرخش', 'ساعت', 'بین ۱ تا ۷۲۰ ساعت؛ هر مشتری هر این‌قدر یک بار.')->when('wheel_enabled', [true]),
            FieldDef::money('wheel_cost', 'هزینه هر چرخش', 'از کیف پول مشتری کم می‌شود؛ صفر یعنی چرخش رایگان است.')->when('wheel_enabled', [true]),
            FieldDef::toggle('wheel_buyers_only', 'فقط مشتری‌هایی که خرید کرده‌اند'),
        ];
    }

    protected function source(): SettingsSource
    {
        return new BotGroupSource('wheel', $this->settings);
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $notice = Links::menuNotice($admin, (bool) ($values['wheel_enabled'] ?? false), 'wheel', 'گردونه شانس', 'چرخاندن گردونه در ربات');
        if ($notice !== null) {
            $view->line()->line(Fmt::e($notice[0]))->row($notice[1]);
        }
    }
}
