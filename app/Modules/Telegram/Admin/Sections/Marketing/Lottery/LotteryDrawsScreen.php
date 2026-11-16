<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Lottery;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Support\LocalTime;
use App\Support\Persian;

/**
 * «🎲 قرعه‌کشی» (the panel's «قرعه‌کشی»): the nightly draw as it stands — on, its hour, winners and prize; off, a tap turns
 * it on through its card (LotterySettingsScreen, «⚙️ تنظیمات») — and the draws made, newest first, each a button with
 * its night, entries and winners, opening its own screen (LotteryDrawScreen); the panel's ⓘ, folded. The panel has no
 * route for one draw: the page's rows are kept for it.
 *
 * Actions: ListScreen's; `info` (an older window's «❔ راهنما»: the list, its help folded in it).
 */
final class LotteryDrawsScreen extends ListScreen
{
    public const ID = 'lt';

    public const TITLE = Icons::LOTTERY . ' ' . Marketing::LOTTERY;

    /** The program in a paragraph: the panel's ⓘ. */
    private const PROGRAM = 'هر شب یک قرعه‌کشی میان خریدارهای بعد از قرعه‌کشی قبلی؛ برنده‌ها جایزه‌شان را همان لحظه می‌گیرند و نتیجه در گروه گزارش‌ها، زیر «خریدها»، هم می‌آید. هر روز فروشگاه فقط یک قرعه‌کشی دارد، حتی اگر ساعتش را عوض کنید. مدیرهای ربات و مشتری‌های مسدود هیچ‌وقت شرکت داده نمی‌شوند. برنده‌ای که جایزه به او نمی‌رسد (مثلا برای جایزه حجم، سرویس فعالی ندارد) کنار می‌رود و نفر بعدی قرعه برنده می‌شود.';

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
        return self::TITLE;
    }

    protected function path(): array
    {
        return Marketing::PATH;
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

    protected function noun(): string
    {
        return 'قرعه‌کشی';
    }

    protected function empty(): string
    {
        return 'هنوز قرعه‌کشی‌ای انجام نشده است. وقتی قرعه‌کشی شبانه روشن باشد، هر شب نتیجه‌اش این‌جا می‌آید: تعداد شانس‌ها، جایزه و برنده‌ها.';
    }

    protected function summary(array $meta): array
    {
        $on = (bool) ($this->settings['lottery_enabled'] ?? false);
        if (!$on) {
            return ['قرعه‌کشی شبانه: ' . Fmt::onOff(false)];
        }
        $prize = LotterySettingsScreen::prize($this->settings, ($this->settings['lottery_prize'] ?? null) === 'plan' && $this->here !== null ? Marketing::plans($this->here) : []);

        return [
            'قرعه‌کشی شبانه: ' . Fmt::onOff(true),
            '<blockquote>هر شب ساعت: <b>' . LotterySettingsScreen::clock((int) ($this->settings['lottery_hour'] ?? 0)) . '</b>' . "\n"
                . 'برنده‌ها: <b>' . Fmt::number((int) ($this->settings['lottery_winners'] ?? 1)) . '</b>' . "\n"
                . 'جایزه هر برنده: <b>' . Fmt::e($prize) . '</b></blockquote>',
        ];
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function parent(): string
    {
        return Marketing::MENU;
    }

    protected function item(array $row): Button
    {
        $winners = count(Json::part($row, 'winners'));
        $entries = (int) ($row['entries'] ?? 0);
        $result = $winners > 0 ? Fmt::number($winners) . ' برنده' : ($entries === 0 ? 'بدون شرکت‌کننده' : 'بدون برنده');

        return Button::to(Icons::LOTTERY . ' ' . self::night($row['day'] ?? null) . ' · ' . Fmt::number($entries) . ' شانس · ' . $result, LotteryDrawScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        $on = (bool) ($this->settings['lottery_enabled'] ?? false);
        $view->row(...LotterySettingsScreen::buttons($on, 'قرعه‌کشی شبانه'))
            ->help(Fmt::e(self::PROGRAM));
    }

    /** A draw's night (its shop day, "2026-11-14") as the bot says dates: «۲۳ آبان ۱۴۰۵». */
    public static function night(mixed $day): string
    {
        $date = is_string($day) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $day, LocalTime::zone()) : false;

        return $date === false ? Fmt::NONE : Persian::date($date->setTime(12, 0));
    }
}
