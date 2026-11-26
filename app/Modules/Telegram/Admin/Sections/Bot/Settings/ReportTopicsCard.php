<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Sections\Bot\ReportGroupScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Lottery\LotterySettingsScreen;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Reports\Topic;

/**
 * «بخش‌های گزارش» (`reports`, under «گروه گزارش‌ها»): a switch per topic of the report group, each with the panel's title
 * and what it reports; the agency's only in the main shop (an agent's group has none, nor its switch) — and, while the
 * daily report is on, the hour it goes from, a number with the panel's bounds and default (described()).
 */
final class ReportTopicsCard extends BotCard
{
    public const ID = 'rgt';

    public const TITLE = Icons::REPORT_TOPICS . ' بخش‌های گزارش';

    protected function group(): string
    {
        return 'reports';
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
        return [...BotScreens::PATH, Fmt::bare(ReportGroupScreen::TITLE)];
    }

    protected function parent(): string
    {
        return ReportGroupScreen::ID;
    }

    /** Back to the group's card where it was left, its origin kept (a 0.10.0 window's «🤖 تنظیمات ربات»). */
    protected function parentAction(): string
    {
        return ReportGroupScreen::VIEW;
    }

    protected function intro(): string
    {
        return 'هر دکمه 🟢 یا ⏸ یک تاپیک گروه گزارش‌ها و حالتش است؛ با یک ضربه روشن یا خاموش می‌شود. ساعت گزارش روزانه هم، تا وقتی آن بخش روشن است، همین‌جا عوض می‌شود.';
    }

    /**
     * Thirteen switches alike (twelve in an agent's shop): chips, two a row, the state on each (SettingsScreen::chips());
     * the daily report's hour, no switch, in the summary with its own «✏️».
     */
    protected function chips(): bool
    {
        return true;
    }

    protected function notes(): array
    {
        return ['بخشی که خاموش باشد گزارشی نمی‌فرستد؛ تاپیکش در گروه می‌ماند.'];
    }

    protected function fields(): array
    {
        return $this->described([
            ...array_map(static function (Topic $topic): FieldDef {
                $field = FieldDef::toggle('report_' . $topic->value, $topic->title(), $topic->about());

                // A switch the shop has not (the agency's, in an agent's shop) is not among the values: not shown.
                return $field->when('report_' . $topic->value, [true, false]);
            }, Topic::cases()),
            FieldDef::number('daily_report_hour', 'ساعت گزارش روزانه', '', 'به وقت منطقه زمانی فروشگاه؛ از این ساعت به بعد، گزارش روزی که گذشت یک بار فرستاده می‌شود. ۰ یعنی کمی بعد از نیمه‌شب.')
                ->example('۹')->when('report_daily', [true]),
        ]);
    }

    protected function value(FieldDef $field, array $values): string
    {
        if ($field->name === 'daily_report_hour' && is_numeric($values['daily_report_hour'] ?? null)) {
            return LotterySettingsScreen::clock((int) $values['daily_report_hour']);
        }

        return parent::value($field, $values);
    }
}
