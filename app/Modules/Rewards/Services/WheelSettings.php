<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * The lucky wheel's settings (the `wheel` group, off by default): the cooldown between spins, whether only buyers may
 * spin, and what a spin costs from the wallet (0: free).
 */
final class WheelSettings implements DeclaresSettings
{
    /** The most a spin may cost, Toman. */
    public const COST_MAX = 10_000_000;

    private readonly Toggle $enabled;
    private readonly Number $cooldown;
    private readonly Toggle $buyersOnly;
    private readonly Number $cost;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('wheel_enabled', 'wheel.enabled', default: false);
        $this->cooldown = new Number('wheel_cooldown_hours', 'wheel.cooldown_hours', default: 24, label: 'فاصله دو چرخش', min: 1, max: 720, unit: 'ساعت');
        $this->buyersOnly = new Toggle('wheel_buyers_only', 'wheel.buyers_only', default: false);
        $this->cost = new Number('wheel_cost', 'wheel.cost', default: 0, label: 'هزینه هر چرخش', min: 0, max: self::COST_MAX, unit: 'تومان');
    }

    public function groups(): array
    {
        return [new Form('wheel', [$this->enabled, $this->cooldown, $this->buyersOnly, $this->cost])];
    }

    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }

    /** The hours a customer waits after a spin that was given before the next. */
    public function cooldownHours(): int
    {
        return $this->settings->read($this->cooldown);
    }

    /** Whether only customers who have bought something with a price may spin (Order::paidPurchases()). */
    public function buyersOnly(): bool
    {
        return $this->settings->read($this->buyersOnly);
    }

    /** What a spin costs from the wallet, whole Toman; 0 — free. */
    public function cost(): int
    {
        return $this->settings->read($this->cost);
    }
}
