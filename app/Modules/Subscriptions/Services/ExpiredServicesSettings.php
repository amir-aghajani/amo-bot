<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * The automatic removal of the shop's ended services (the `expired_services` group, off by default): whether a service
 * whose time or traffic ran out — or that its panel no longer has — is removed from its panel and the shop by itself,
 * and how many hours after it ended. Free test accounts go by their own rule (TestAccountSettings).
 */
final class ExpiredServicesSettings implements DeclaresSettings
{
    /** The longest a shop may keep an ended service before it goes, hours (a year). */
    public const HOURS_MAX = 8760;

    private readonly Toggle $enabled;
    private readonly Number $hours;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('expired_cleanup', 'expired.cleanup', default: false);
        $this->hours = new Number('expired_cleanup_hours', 'expired.cleanup_hours', default: 24, label: 'فاصله حذف', min: 0, max: self::HOURS_MAX, unit: 'ساعت');
    }

    public function groups(): array
    {
        return [new Form('expired_services', [$this->enabled, $this->hours])];
    }

    /** Whether the shop removes its ended services by itself. */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }

    /** How long an ended service is kept before it is removed, in hours; 0 — at once. */
    public function hours(): int
    {
        return $this->settings->read($this->hours);
    }
}
