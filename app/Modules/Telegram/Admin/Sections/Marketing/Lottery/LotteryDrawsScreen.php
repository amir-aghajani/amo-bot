<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Lottery;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Support\LocalTime;
use App\Support\Persian;

/**
 * «🎲 قرعه‌کشی» (the panel's «قرعه‌کشی»): the nightly draw's rules as they stand (LotterySettingsScreen) and the draws made,
 * newest first — each night's entries and winners, opening its own screen (LotteryDrawScreen). The panel has no route
 * for one draw: the page's rows are kept for it.
 *
 * Actions: `info`.
 */
final class LotteryDrawsScreen extends ListScreen
{
    public const ID = 'lt';

    /**
     * @var array<string, mixed> The bot's settings, read as each update opens the list
     */
    private array $settings = [];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $this->settings = BotGroupSource::settings($admin);

        return parent::show($admin, $args);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🎲 قرعه‌کشی';
    }

    protected function path(): array
    {
        return Words::PATH;
    }

    protected function endpoint(): string
    {
        return '/lottery/draws';
    }

    protected function key(): string
    {
        return 'draws';
    }

    protected function searchable(): bool
    {
        return false;
    }

    protected function empty(): string
    {
        return 'هنوز قرعه‌کشی‌ای انجام نشده است. وقتی قرعه‌کشی شبانه روشن باشد، هر شب نتیجه‌اش این‌جا می‌آید: تعداد شانس‌ها، جایزه و برنده‌ها.';
    }

    protected function summary(array $meta): array
    {
        $on = (bool) ($this->settings['lottery_enabled'] ?? false);
        $line = 'قرعه‌کشی شبانه: ' . Fmt::onOff($on);
        if ($on) {
            $line .= ' · ساعت ' . Persian::digits(sprintf('%02d:00', (int) ($this->settings['lottery_hour'] ?? 0))) . ' · ' . Fmt::number((int) ($this->settings['lottery_winners'] ?? 1)) . ' برنده';
        }

        return ['هر شب یک قرعه‌کشی میان خریدارهای بعد از قرعه‌کشی قبلی؛ برنده‌ها جایزه‌شان را همان لحظه می‌گیرند و نتیجه در گروه گزارش‌ها، زیر «خریدها»، هم می‌آید.', $line];
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function parent(): string
    {
        return 'hm';
    }

    protected function item(array $row): Button
    {
        $winners = count(Json::part($row, 'winners'));
        $entries = (int) ($row['entries'] ?? 0);
        $result = $winners > 0 ? Fmt::number($winners) . ' برنده' : ($entries === 0 ? 'بدون شرکت‌کننده' : 'بدون برنده');

        return Button::to('🎲 ' . self::night($row['day'] ?? null) . ' · ' . Fmt::number($entries) . ' شانس · ' . $result, LotteryDrawScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->row(Button::to('⚙️ تنظیمات قرعه‌کشی', LotterySettingsScreen::ID), Button::to('❔ راهنما', self::ID, 'info'));
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action === 'info') {
            return Words::help(self::ID, 'قرعه‌کشی', 'هر روز فروشگاه فقط یک قرعه‌کشی دارد، حتی اگر ساعتش را عوض کنید. مدیرهای ربات و مشتری‌های مسدود هیچ‌وقت شرکت داده نمی‌شوند. برنده‌ای که جایزه به او نمی‌رسد (مثلا برای جایزه حجم، سرویس فعالی ندارد) کنار می‌رود و نفر بعدی قرعه برنده می‌شود.');
        }

        return $this->show($admin, []);
    }

    /** A draw's night (its shop day, "2026-11-14") as the bot says dates: «۲۳ آبان ۱۴۰۵». */
    public static function night(mixed $day): string
    {
        $date = is_string($day) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $day, LocalTime::zone()) : false;

        return $date === false ? Fmt::NONE : Persian::date($date->setTime(12, 0));
    }
}
