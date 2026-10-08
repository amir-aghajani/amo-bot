<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Services;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * The admin's referral program («زیرمجموعه‌گیری», the `referral` group of the bot settings): whether it runs, what share
 * of a referred customer's payment their referrer earns, and whether only the first payment of each referred customer
 * earns it. Off until the admin turns it on.
 */
final class ReferralSettings implements DeclaresSettings
{
    private readonly Toggle $enabled;
    private readonly Number $rate;
    private readonly Toggle $firstOnly;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('referral_enabled', 'referral.enabled', default: false);
        $this->rate = new Number('referral_rate', 'referral.rate', default: 10, label: 'درصد پورسانت', min: 1, max: 100);
        $this->firstOnly = new Toggle('referral_first_only', 'referral.first_only', default: false);
    }

    public function groups(): array
    {
        return [new Form('referral', [$this->enabled, $this->rate, $this->firstOnly])];
    }

    /** Whether links bring referrals and payments earn commissions. */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }

    /** The share of a referred customer's payment, in percent, credited to the referrer. */
    public function rate(): int
    {
        return $this->settings->read($this->rate);
    }

    /** Whether only the first payment of each referred customer earns a commission. */
    public function firstOnly(): bool
    {
        return $this->settings->read($this->firstOnly);
    }
}
