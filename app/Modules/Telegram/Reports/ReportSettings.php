<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Bots\CurrentBot;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * Which topics of the report group the admin wants (the `reports` group of the bot settings, a switch per topic — all
 * on until switched off; every bot its own). The agency's topic is the main bot's alone: agency requests and traffic
 * purchases are reported to the shop's own group, so an agent's group has no such topic and its bot no switch for it.
 * The group itself — which one is connected, what keeps reports from it — is runtime state, ReportGroupState's.
 */
final class ReportSettings implements DeclaresSettings
{
    public function __construct(private readonly Settings $settings) {}

    /** @return list<Topic> The topics the current bot's group has: every one for the main bot, all but the agency's for an agent's. */
    public static function topics(): array
    {
        return CurrentBot::isMain() ? Topic::cases() : array_values(array_filter(Topic::cases(), static fn(Topic $topic): bool => $topic !== Topic::Agency));
    }

    public function groups(): array
    {
        return [new Form('reports', array_map(self::topicSwitch(...), self::topics()))];
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
