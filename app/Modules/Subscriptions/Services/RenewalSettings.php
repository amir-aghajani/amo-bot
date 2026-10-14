<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Ruled;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;
use App\Modules\Subscriptions\Enums\RenewalMethod;

/**
 * The admin's renewal rules («تمدید سرویس» in the bot settings, two groups). `renewal`: whether the traffic a period
 * leaves unused carries into the renewed one or is lost when the paid period ends (the days left always carry); when a
 * customer may renew — only within so many days of the end, or with so little of the traffic left (0: either way off) —;
 * and how a renewal adds its package (Enums\RenewalMethod): the methods offered (one at least), the customer picking among
 * them when there is more than one, and the one automatic renewal uses (one of those on). `auto_renew` («تمدید خودکار»):
 * how many days before its deadline a service is renewed from the wallet, and whether a new service starts with the
 * switch on.
 */
final class RenewalSettings implements DeclaresSettings
{
    /** A save that leaves no method on, or a default among those off. */
    public const NO_METHOD = 'دست‌کم یک روش تمدید را روشن کنید.';
    public const DEFAULT_OFF = 'روش پیش‌فرض باید یکی از روش‌های روشن باشد.';

    /** Each method's switch, by RenewalMethod value. */
    private const SWITCHES = [
        'extend' => 'renew_extend',
        'reset_traffic' => 'renew_reset_traffic',
        'reset_time' => 'renew_reset_time',
    ];

    private readonly Toggle $carryTraffic;
    private readonly Number $windowDays;
    private readonly Number $windowPercent;
    /** @var array<string, Field<bool>> By RenewalMethod value: whether the shop offers it */
    private readonly array $methods;
    /** @var Field<string> */
    private readonly Field $defaultMethod;
    private readonly Number $autoRenewDays;
    private readonly Toggle $autoRenewDefault;

    public function __construct(private readonly Settings $settings)
    {
        $this->carryTraffic = new Toggle('carry_traffic', 'renewal.carry_traffic', default: false);
        $this->windowDays = new Number('renew_window_days', 'renewal.window_days', default: 0, label: 'بازه تمدید', min: 0, max: 365, unit: 'روز');
        $this->windowPercent = new Number('renew_window_percent', 'renewal.window_percent', default: 0, label: 'حجم باقی‌مانده برای تمدید', min: 0, max: 100, unit: 'درصد');
        $this->methods = [
            // The first switch says so when a save leaves none on.
            RenewalMethod::Extend->value => new Ruled(
                new Toggle(self::SWITCHES['extend'], 'renewal.method_extend', default: true),
                static fn(array $values): ?string => self::switchedOn($values) === [] ? self::NO_METHOD : null,
            ),
            RenewalMethod::ResetTraffic->value => new Toggle(self::SWITCHES['reset_traffic'], 'renewal.method_reset_traffic', default: false),
            RenewalMethod::ResetTime->value => new Toggle(self::SWITCHES['reset_time'], 'renewal.method_reset_time', default: false),
        ];
        $this->defaultMethod = new Ruled(new Choice(
            'renew_default_method',
            'renewal.default_method',
            RenewalMethod::Extend->value,
            array_map(static fn(RenewalMethod $method): string => $method->value, RenewalMethod::cases()),
            'روش تمدید پیش‌فرض یکی از سه روش تمدید است.',
        ), static function (array $values): ?string {
            $on = self::switchedOn($values);
            $default = $values['renew_default_method'] ?? null;

            return $on !== [] && !in_array($default, $on, true) ? self::DEFAULT_OFF : null;
        });
        $this->autoRenewDays = new Number('auto_renew_days', 'auto_renew.days_before', default: 2, label: 'تعداد روز', min: 1, max: 30);
        $this->autoRenewDefault = new Toggle('auto_renew_default', 'auto_renew.default', default: false);
    }

    public function groups(): array
    {
        return [
            new Form('renewal', [$this->carryTraffic, $this->windowDays, $this->windowPercent, ...array_values($this->methods), $this->defaultMethod]),
            new Form('auto_renew', [$this->autoRenewDays, $this->autoRenewDefault]),
        ];
    }

    /** Whether the traffic a period leaves unused is added to the renewed one; otherwise it is lost when that period ends. */
    public function carriesTraffic(): bool
    {
        return $this->settings->read($this->carryTraffic);
    }

    /** Within how many days of its deadline a customer may renew a running service; 0 — any time. */
    public function windowDays(): int
    {
        return $this->settings->read($this->windowDays);
    }

    /** With at most what percent of its traffic left a customer may renew a running service; 0 — however much is left. */
    public function windowPercent(): int
    {
        return $this->settings->read($this->windowPercent);
    }

    /**
     * The methods the shop offers, in the enum's order — Extend alone should none be on (a save refuses that).
     *
     * @return non-empty-list<RenewalMethod>
     */
    public function methods(): array
    {
        $offered = array_values(array_filter(RenewalMethod::cases(), fn(RenewalMethod $method): bool => $this->settings->read($this->methods[$method->value])));

        return $offered === [] ? [RenewalMethod::Extend] : $offered;
    }

    /** The method automatic renewal uses: the admin's default while it is offered, else the first offered. */
    public function defaultMethod(): RenewalMethod
    {
        $chosen = RenewalMethod::from($this->settings->read($this->defaultMethod));
        $offered = $this->methods();

        return in_array($chosen, $offered, true) ? $chosen : $offered[0];
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

    /**
     * The methods a save of the form leaves on, as RenewalMethod values — a switch refused is none.
     *
     * @param array<string, mixed> $values By field name
     * @return list<string>
     */
    private static function switchedOn(array $values): array
    {
        return array_keys(array_filter(self::SWITCHES, static fn(string $name): bool => ($values[$name] ?? null) === true));
    }
}
