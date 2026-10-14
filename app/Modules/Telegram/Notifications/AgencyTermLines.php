<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Notifications;

use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Support\JalaliMonth;
use App\Support\Money;
use App\Support\Persian;

/**
 * An agent's terms of selling as the bot words them (Agency\Services\AgencyStanding), a line each — the agency group's
 * «پایان نمایندگی», «کف خرید ماهانه» or «خرید این ماه», and «فروش متوقف»: under a notice of their terms
 * (CustomerNotifier) and under their account (AgencyHandler). In the main shop, whose texts they are.
 */
final class AgencyTermLines
{
    public function __construct(private readonly BotTexts $texts) {}

    /** Under a notice of the agent's terms (approved, changed): their agency's end and their minimum; '' for neither. */
    public function terms(?Bot $bot): Html|string
    {
        if ($bot === null) {
            return '';
        }

        return $this->lines($bot, $bot->monthly_minimum === null ? null : $this->texts->part(BotText::AgencyMinimumLine, ['minimum' => Money::format($bot->monthly_minimum)]));
    }

    /**
     * Under the agent's account: their agency's end, `$month`'s traffic bought (`$purchased`) against their minimum, and
     * why their bot sells nothing now; '' while there is none of these.
     */
    public function standing(Bot $bot, JalaliMonth $month, string $purchased): Html|string
    {
        $suspension = $bot->sellingSuspension();

        return $this->lines(
            $bot,
            $bot->monthly_minimum === null ? null : $this->texts->part(BotText::AgencyMonthLine, ['month' => $month->name(), 'purchased' => Money::format($purchased), 'minimum' => Money::format($bot->monthly_minimum)]),
            $suspension === null ? null : $this->texts->part(BotText::AgencySellingLine, ['reason' => $suspension->words()]),
        );
    }

    /** The end's line, then `$more` that are given. */
    private function lines(Bot $bot, ?Html ...$more): Html|string
    {
        $lines = array_values(array_filter([
            $bot->agency_expires_at === null ? null : $this->texts->part(BotText::AgencyExpiryLine, ['date' => Persian::date($bot->agency_expires_at, withTime: true)]),
            ...$more,
        ]));

        return $lines === [] ? '' : Html::lines(...$lines);
    }
}
