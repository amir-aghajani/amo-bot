<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * The admin's renewal rules («تمدید سرویس» in the bot settings, two groups). `renewal`: whether the traffic a period
 * leaves unused carries into the renewed one or is lost when the paid period ends (the days left always carry).
 * `auto_renew` («تمدید خودکار»): how many days before its deadline a service is renewed from the wallet, and whether a
 * new service starts with the switch on.
 */
final class RenewalSettings implements DeclaresSettings
{
    private readonly Toggle $carryTraffic;
    private readonly Number $autoRenewDays;
    private readonly Toggle $autoRenewDefault;

    public function __construct(private readonly Settings $settings)
    {
        $this->carryTraffic = new Toggle('carry_traffic', 'renewal.carry_traffic', default: false);
        $this->autoRenewDays = new Number('auto_renew_days', 'auto_renew.days_before', default: 2, label: 'تعداد روز', min: 1, max: 30);
        $this->autoRenewDefault = new Toggle('auto_renew_default', 'auto_renew.default', default: false);
    }

    public function groups(): array
    {
        return [
            new Form('renewal', [$this->carryTraffic]),
            new Form('auto_renew', [$this->autoRenewDays, $this->autoRenewDefault]),
        ];
    }

    /** Whether the traffic a period leaves unused is added to the renewed one; otherwise it is lost when that period ends. */
    public function carriesTraffic(): bool
    {
        return $this->settings->read($this->carryTraffic);
    }

    /** How many days before its deadline a service is renewed automatically. */
    public function autoRenewDays(): int
    {
        return $this->settings->read($this->autoRenewDays);
    }

    /** That window in hours, the unit a term is counted in. */
    public function autoRenewHours(): int
    {
        return $this->autoRenewDays() * ServiceTerms::HOURS_A_DAY;
    }

    /** Whether a new service starts with the customer's «تمدید خودکار» on. */
    public function autoRenewByDefault(): bool
    {
        return $this->settings->read($this->autoRenewDefault);
    }

    /**
     * Whether a service of `$plan` may renew itself: its term is at least the window. A shorter term (an hourly plan's)
     * would leave the service still due after renewing, renewed again and again within one window; a plan that never
     * ends (0) needs no renewal.
     */
    public function autoRenews(Plan $plan): bool
    {
        return $plan->duration_hours >= $this->autoRenewHours();
    }
}
