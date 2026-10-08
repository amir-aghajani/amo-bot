<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * The admin's reminders («یادآوری», the `reminders` group of the bot settings): whether a customer is told in the bot
 * that a service ends soon — how many days before its deadline — and that its traffic runs low — once how much of its
 * quota is used. Both off until the admin turns them on.
 */
final class ReminderSettings implements DeclaresSettings
{
    private readonly Toggle $expiry;
    private readonly Number $expiryDays;
    private readonly Toggle $traffic;
    private readonly Number $trafficPercent;

    public function __construct(private readonly Settings $settings)
    {
        $this->expiry = new Toggle('expiry_reminder', 'reminders.expiry', default: false);
        $this->expiryDays = new Number('expiry_reminder_days', 'reminders.expiry_days', default: 3, label: 'تعداد روز', min: 1, max: 30);
        $this->traffic = new Toggle('traffic_reminder', 'reminders.traffic', default: false);
        $this->trafficPercent = new Number('traffic_reminder_percent', 'reminders.traffic_percent', default: 80, label: 'درصد', min: 50, max: 99);
    }

    public function groups(): array
    {
        return [new Form('reminders', [$this->expiry, $this->expiryDays, $this->traffic, $this->trafficPercent])];
    }

    /** Whether a customer is reminded that a service ends soon. */
    public function expiryEnabled(): bool
    {
        return $this->settings->read($this->expiry);
    }

    /** How many days before its deadline that reminder comes. */
    public function expiryDays(): int
    {
        return $this->settings->read($this->expiryDays);
    }

    /** Whether a customer is reminded that a service's traffic runs low. */
    public function trafficEnabled(): bool
    {
        return $this->settings->read($this->traffic);
    }

    /** How much of its quota, in percent, a service has used when that reminder comes. */
    public function trafficPercent(): int
    {
        return $this->settings->read($this->trafficPercent);
    }
}
