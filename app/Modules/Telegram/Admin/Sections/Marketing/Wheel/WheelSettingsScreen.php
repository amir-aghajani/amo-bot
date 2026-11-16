<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Wheel;

use App\Modules\Telegram\Admin\Sections\Bot\Settings\BotCard;
use App\Modules\Telegram\Admin\Sections\Marketing\ProgramSwitch;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «⚙️ تنظیمات گردونه» (the panel's «قوانین گردونه», `wheel`), reached from the prizes' list: the switch, the wait between
 * spins and a spin's cost — held while the wheel is off, as the panel holds them —, and whether only buyers spin; with
 * the panel's warning, and the way to the keyboards, when it is on and the bot's menu has no button for it.
 */
final class WheelSettingsScreen extends BotCard
{
    use ProgramSwitch;

    public const ID = 'whs';

    protected function group(): string
    {
        return 'wheel';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return Marketing::settings('گردونه');
    }

    protected function path(): array
    {
        return Marketing::in(Marketing::WHEEL);
    }

    protected function parent(): string
    {
        return WheelPrizesScreen::ID;
    }

    protected function intro(): string
    {
        return 'مشتری هر چند ساعت یک بار گردونه را می‌چرخاند و یکی از جایزه‌ها را می‌برد؛ چرخشی که جایزه‌اش داده نشود حساب نمی‌شود.' . "\n\n"
            . 'روشن که باشد، دکمه «گردونه شانس» در منوی ربات می‌آید. خاموش، دکمه از منو برداشته می‌شود و جایزه‌ها و چرخش‌های قبلی سر جایشان می‌مانند؛ فاصله و هزینه هم تا روشن شدن همان‌طور می‌مانند.';
    }

    protected function fields(): array
    {
        return $this->described([
            FieldDef::toggle('wheel_enabled', 'گردونه شانس فعال باشد'),
            FieldDef::number('wheel_cooldown_hours', 'فاصله دو چرخش', 'ساعت', 'هر مشتری هر این‌قدر یک بار می‌چرخاند.')->example('۲۴')->when('wheel_enabled', [true]),
            FieldDef::money('wheel_cost', 'هزینه هر چرخش', 'از کیف پول مشتری کم می‌شود؛ صفر یعنی چرخش رایگان است.')->example('۰')->when('wheel_enabled', [true]),
            FieldDef::toggle('wheel_buyers_only', 'فقط مشتری‌هایی که خرید کرده‌اند', 'فقط مشتری‌ای که دست‌کم یک سرویس خریده گردونه را می‌چرخاند؛ مدیرهای ربات همیشه می‌توانند.'),
        ]);
    }

    protected function shows(FieldDef $field, mixed $value): ?string
    {
        return $field->name === 'wheel_cost' && (float) $value <= 0 ? 'رایگان' : null;
    }

    protected function warnings(Admin $admin, array $values): array
    {
        $notice = WheelPrizesScreen::notice($admin, (bool) ($values['wheel_enabled'] ?? false));

        return $notice === null ? [] : [$notice[0]];
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $view->row(WheelPrizesScreen::notice($admin, (bool) ($values['wheel_enabled'] ?? false))[1] ?? null);
    }
}
