<?php

declare(strict_types=1);

namespace App\Modules\Agency\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Amount;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Numbers;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Services\Settings;
use App\Support\Money;

/**
 * The agency program's settings, kept in the main bot's rows whichever shop reads them (off by default): whether it
 * takes requests (agents keep their bots either way), an approved agent's starting credit, the traffic amounts an
 * agent may buy, and an approved agent's starting terms of selling — how long their agency lasts and how much traffic
 * they buy a month at least (AgencyStanding) — with the days an agent is warned before either bites.
 */
final class AgencySettings
{
    /** The most traffic one purchase may bring, in GB. */
    public const TRAFFIC_MAX_GB = 100_000;

    /** The longest an agency may be given at once, in days (ten years). */
    public const TERM_MAX_DAYS = 3650;

    /** The most days before an agency's end, or a month's, an agent is warned. */
    public const WARN_MAX_DAYS = 30;

    private readonly Toggle $enabled;
    private readonly Amount $defaultCredit;
    private readonly Numbers $trafficPresets;
    private readonly Number $trafficMin;
    private readonly Number $termDays;
    private readonly Amount $monthlyMinimum;
    private readonly Number $warnDays;
    private readonly Form $group;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('enabled', 'agency.enabled', default: false);
        $this->defaultCredit = self::creditField('default_credit', 'اعتبار اولیه');
        $this->trafficMin = new Number('traffic_min', 'agency.traffic_min', default: 10, label: 'کمترین خرید حجم', min: 1, max: self::TRAFFIC_MAX_GB, unit: 'گیگابایت');
        $this->trafficPresets = new Numbers('traffic_presets', 'agency.traffic_presets', default: [50, 100, 200, 500], label: 'حجم‌های پیشنهادی', min: 1, max: self::TRAFFIC_MAX_GB, most: 8, unit: 'گیگابایت', atLeast: $this->trafficMin);
        $this->termDays = new Number('term_days', 'agency.term_days', default: 0, label: 'مدت نمایندگی', min: 0, max: self::TERM_MAX_DAYS, unit: 'روز');
        $this->monthlyMinimum = self::minimumField();
        $this->warnDays = new Number('warn_days', 'agency.warn_days', default: 3, label: 'هشدار پیش از توقف', min: 1, max: self::WARN_MAX_DAYS, unit: 'روز');
        $this->group = new Form('agency', [$this->enabled, $this->defaultCredit, $this->trafficPresets, $this->trafficMin, $this->termDays, $this->monthlyMinimum, $this->warnDays]);
    }

    /** Whether the program takes requests and shows its button to customers. */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled, Bot::MAIN);
    }

    /** The credit an approved agent starts with, in Toman — what their wallet may go below zero by. */
    public function defaultCredit(): string
    {
        return $this->settings->read($this->defaultCredit, Bot::MAIN);
    }

    /**
     * An agent's own credit, as the agents page gives it (DTO\AgencyTerms), held to the program's default credit's rule:
     * whole Toman from 0 up to Money::AMOUNT_MAX.
     *
     * @param array<string, mixed> $input {credit_limit}
     * @throws FieldRefused worded as the program's field is, under the agent's own label
     */
    public static function creditOf(array $input): string
    {
        return self::creditField('credit_limit', 'اعتبار')->read($input, null);
    }

    /** @return list<int> The GB an agent may buy with one tap, smallest first. */
    public function trafficPresets(): array
    {
        return $this->settings->read($this->trafficPresets, Bot::MAIN);
    }

    /** The least GB an agent may type. */
    public function trafficMin(): int
    {
        return $this->settings->read($this->trafficMin, Bot::MAIN);
    }

    /** The days an approved agent's agency lasts from their approval; null for no end. */
    public function termDays(): ?int
    {
        $days = $this->settings->read($this->termDays, Bot::MAIN);

        return $days > 0 ? $days : null;
    }

    /** The Toman of traffic an approved agent buys each Jalali month at least; null for no minimum. */
    public function monthlyMinimum(): ?string
    {
        return self::minimumOrNone($this->settings->read($this->monthlyMinimum, Bot::MAIN));
    }

    /**
     * An agent's own monthly minimum, as the agents page gives it (DTO\SellingTerms), held to the program's field: whole
     * Toman up to Money::AMOUNT_MAX, 0 for none (null).
     *
     * @param array<string, mixed> $input {monthly_minimum}
     * @throws FieldRefused worded as the program's field is
     */
    public static function minimumOf(array $input): ?string
    {
        return self::minimumOrNone(self::minimumField()->read($input, null));
    }

    /** How many days before their agency's end, or before a month ends with their minimum unmet, an agent is warned. */
    public function warnDays(): int
    {
        return $this->settings->read($this->warnDays, Bot::MAIN);
    }

    /** @return array<string, mixed> The rules as the owner's screen shows them */
    public function present(): array
    {
        return $this->settings->present($this->group, Bot::MAIN);
    }

    /**
     * The owner's rules, checked and kept — all of them, or nothing.
     *
     * @param array<string, mixed> $input {enabled, default_credit, traffic_presets: list<int|string> | "50, 100", traffic_min, term_days, monthly_minimum, warn_days}
     * @throws ValidationException
     */
    public function save(array $input): void
    {
        $this->settings->save($this->group, $input, Bot::MAIN);
    }

    /**
     * The credit's one rule, under the form's own name and label: the program's starting credit (`default_credit`) and
     * each agent's own (`credit_limit`, creditOf()).
     */
    private static function creditField(string $name, string $label): Amount
    {
        return new Amount($name, 'agency.default_credit', default: '0', label: $label, min: '0', max: (string) Money::AMOUNT_MAX);
    }

    /** The monthly minimum's one field: the program's — an agent approved now — and each agent's own (minimumOf()). */
    private static function minimumField(): Amount
    {
        return new Amount('monthly_minimum', 'agency.monthly_minimum', default: '0', label: 'کف خرید ماهانه', min: '0', max: (string) Money::AMOUNT_MAX);
    }

    /** A minimum as the field holds it, as the terms keep it: null for none (0). */
    private static function minimumOrNone(string $minimum): ?string
    {
        return Money::isPositive($minimum) ? $minimum : null;
    }
}
