<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Bots\CurrentBot;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * Which report topics the admin wants: the `reports` group of the bot settings, a switch per topic, all on by default —
 * and the hour of the shop's day the daily report («گزارش روزانه», DailyReport) of the day before is due from. An agent's
 * bot has no agency topic: agency news goes to the main bot's group.
 */
final class ReportSettings implements DeclaresSettings
{
    private readonly Number $dailyHour;

    public function __construct(private readonly Settings $settings)
    {
        $this->dailyHour = new Number('daily_report_hour', 'reports.daily_hour', default: 0, label: 'ساعت گزارش روزانه', min: 0, max: 23);
    }

    /** @return list<Topic> The topics the current bot's group has: every one for the main bot, all but the agency's for an agent's. */
    public static function topics(): array
    {
        return CurrentBot::isMain() ? Topic::cases() : array_values(array_filter(Topic::cases(), static fn(Topic $topic): bool => $topic !== Topic::Agency));
    }

    public function groups(): array
    {
        return [new Form('reports', [...array_map(self::topicSwitch(...), self::topics()), $this->dailyHour])];
    }

    /** The hour of the shop's day (its zone's, 0 to 23) the daily report of the day before is due from; 0 just after midnight. */
    public function dailyHour(): int
    {
        return $this->settings->read($this->dailyHour);
    }

    /** Whether the admin wants this topic's reports. */
    public function enabled(Topic $topic): bool
    {
        return $this->settings->read(self::topicSwitch($topic));
    }

    /** A topic's switch: `report_<topic>` on the screen, `reports.topic.<topic>` in the settings. */
    private static function topicSwitch(Topic $topic): Toggle
    {
        return new Toggle('report_' . $topic->value, 'reports.topic.' . $topic->value, default: true);
    }
}
