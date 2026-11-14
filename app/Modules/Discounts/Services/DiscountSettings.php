<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Services;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * The discounts' settings: how long an unpaid order made with a code holds the code's use before ExpireOrdersTask drops
 * it (`discounts`, «آزادسازی خودکار سهمیه کد») — 0 leaves it to the usual expiry of unpaid orders —, and whether those
 * who pay agent prices go without the customers' perks (`agent_perks`, «همکارها فقط با قیمت همکاری», Perks).
 */
final class DiscountSettings implements DeclaresSettings
{
    public const HOLD_MAX = 1440;

    private readonly Number $hold;
    private readonly Toggle $agentsWithoutPerks;

    public function __construct(private readonly Settings $settings)
    {
        $this->hold = new Number('hold_minutes', 'discounts.hold_minutes', default: 60, label: 'مهلت پرداخت سفارش با کد تخفیف', min: 0, max: self::HOLD_MAX, unit: 'دقیقه');
        $this->agentsWithoutPerks = new Toggle('agents_without_perks', 'discounts.agents_without_perks', default: false);
    }

    public function groups(): array
    {
        return [new Form('discounts', [$this->hold]), new Form('agent_perks', [$this->agentsWithoutPerks])];
    }

    /** How many minutes an unpaid order made with a code holds its use; 0 — as long as any unpaid order waits. */
    public function holdMinutes(): int
    {
        return $this->settings->read($this->hold);
    }

    /** Whether those who pay agent prices go without the customers' perks (Perks). */
    public function agentsWithoutPerks(): bool
    {
        return $this->settings->read($this->agentsWithoutPerks);
    }
}
