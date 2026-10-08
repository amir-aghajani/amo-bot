<?php

declare(strict_types=1);

namespace App\Modules\Agency\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Amount;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Numbers;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Services\Settings;

/**
 * The rules of the shop's agency program («تنظیمات», a section of the owner's agents page — `GET|PUT /agency/settings`;
 * the main bot's settings, whichever shop reads them): whether it takes requests — off, the menu's button goes for
 * customers; agents keep their screen and their bots —, the credit an approved agent starts with, and the traffic an
 * agent buys for their bot: the amounts offered as buttons and the least they may type, in GB. Off until the owner
 * turns it on.
 */
final class AgencySettings
{
    /** The most credit an agent may be given, in Toman. */
    public const CREDIT_MAX = 1_000_000_000;

    /** The most traffic one purchase may bring, in GB. */
    public const TRAFFIC_MAX_GB = 100_000;

    private readonly Toggle $enabled;
    private readonly Amount $defaultCredit;
    private readonly Numbers $trafficPresets;
    private readonly Number $trafficMin;
    private readonly Form $group;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('enabled', 'agency.enabled', default: false);
        $this->defaultCredit = new Amount('default_credit', 'agency.default_credit', default: '0', label: 'اعتبار اولیه', min: '0', max: (string) self::CREDIT_MAX);
        $this->trafficMin = new Number('traffic_min', 'agency.traffic_min', default: 10, label: 'کمترین خرید حجم', min: 1, max: self::TRAFFIC_MAX_GB, unit: 'گیگابایت');
        $this->trafficPresets = new Numbers('traffic_presets', 'agency.traffic_presets', default: [50, 100, 200, 500], label: 'حجم‌های پیشنهادی', min: 1, max: self::TRAFFIC_MAX_GB, most: 8, unit: 'گیگابایت', atLeast: $this->trafficMin);
        $this->group = new Form('agency', [$this->enabled, $this->defaultCredit, $this->trafficPresets, $this->trafficMin]);
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

    /** @return array<string, mixed> The rules as the owner's screen shows them */
    public function present(): array
    {
        return $this->settings->present($this->group, Bot::MAIN);
    }

    /**
     * The owner's rules, checked and kept — all of them, or nothing.
     *
     * @param array<string, mixed> $input {enabled, default_credit, traffic_presets: list<int|string> | "50, 100", traffic_min}
     * @throws ValidationException
     */
    public function save(array $input): void
    {
        $this->settings->save($this->group, $input, Bot::MAIN);
    }
}
