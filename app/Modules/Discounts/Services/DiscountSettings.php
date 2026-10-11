<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Services;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * The discount codes' settings (the `discounts` group): how long an unpaid order made with a code holds the code's use
 * before ExpireOrdersTask drops it («آزادسازی خودکار سهمیه کد»). 0 leaves it to the usual expiry of unpaid orders.
 */
final class DiscountSettings implements DeclaresSettings
{
    public const HOLD_MAX = 1440;

    private readonly Number $hold;

    public function __construct(private readonly Settings $settings)
    {
        $this->hold = new Number('hold_minutes', 'discounts.hold_minutes', default: 60, label: 'مهلت پرداخت سفارش با کد تخفیف', min: 0, max: self::HOLD_MAX, unit: 'دقیقه');
    }

    public function groups(): array
    {
        return [new Form('discounts', [$this->hold])];
    }

    /** How many minutes an unpaid order made with a code holds its use; 0 — as long as any unpaid order waits. */
    public function holdMinutes(): int
    {
        return $this->settings->read($this->hold);
    }
}
