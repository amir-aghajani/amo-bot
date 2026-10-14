<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Numbers;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\DTO\Package;
use App\Modules\Catalog\DTO\PriceTiers;
use App\Modules\Catalog\Enums\CustomQuantity;
use App\Modules\Catalog\Forms\TierPrices;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;
use App\Modules\Subscriptions\Forms\ExtraOptions;

/**
 * «سرویس دلخواه» (the `custom_services` group, off by default, the main shop's alone): a customer builds a service of
 * the traffic and days they choose — each quantity (Enums\CustomQuantity) priced by its tiers (DTO\PriceTiers), the two
 * prices added —, within the bounds set here, offered as buttons too, on the servers set here, with the devices set
 * here. The most a quantity may be is a sale's by the unit (DTO\Package::MAX_GB, MAX_DAYS), its tiers' prices too.
 */
final class CustomServiceSettings implements DeclaresSettings
{
    /** The most devices a built service may be set to. */
    public const MAX_DEVICES = 100;

    /** How many servers the builder sells on at most. */
    public const SERVERS_MOST = 50;

    private readonly Toggle $enabled;

    /** @var array<string, TierPrices> Each quantity's fields, by its value */
    private readonly array $tiers;

    /** @var array<string, Number> */
    private readonly array $least;

    /** @var array<string, Number> */
    private readonly array $most;

    /** @var array<string, Numbers> */
    private readonly array $options;

    private readonly Number $devices;
    private readonly Numbers $servers;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('custom_enabled', 'custom.enabled', default: false);
        $tiers = $least = $most = $options = [];
        foreach (CustomQuantity::cases() as $quantity) {
            $name = static fn(string $field): string => "custom_{$quantity->value}_{$field}";
            $key = static fn(string $field): string => "custom.{$quantity->value}_{$field}";
            [$priceLabel, $leastLabel, $mostLabel, $optionsLabel, $defaultMost, $defaultOptions] = match ($quantity) {
                CustomQuantity::Traffic => ['قیمت هر گیگابایت', 'کمترین حجم', 'بیشترین حجم', 'حجم‌های پیشنهادی', 500, [10, 20, 50, 100]],
                CustomQuantity::Days => ['قیمت هر روز', 'کمترین زمان', 'بیشترین زمان', 'زمان‌های پیشنهادی', 90, [30, 60, 90]],
            };
            $max = $quantity->max();
            $unit = $quantity->unit();
            $tiers[$quantity->value] = new TierPrices($name('tiers'), $key('tiers'), $priceLabel, $max, $unit, Package::PRICE_MAX);
            $least[$quantity->value] = new Number($name('min'), $key('min'), default: 1, label: $leastLabel, min: 1, max: $max, unit: $unit);
            $most[$quantity->value] = new Number($name('max'), $key('max'), default: $defaultMost, label: $mostLabel, min: 1, max: $max, unit: $unit, atLeast: $least[$quantity->value]);
            $options[$quantity->value] = new Numbers($name('options'), $key('options'), $defaultOptions, label: $optionsLabel, min: 1, max: $max, most: ExtraOptions::MOST, unit: $unit);
        }
        $this->tiers = $tiers;
        $this->least = $least;
        $this->most = $most;
        $this->options = $options;
        $this->devices = new Number('custom_devices', 'custom.devices', default: 2, label: 'تعداد کاربر هم‌زمان', min: 0, max: self::MAX_DEVICES);
        $this->servers = new Numbers('custom_servers', 'custom.servers', [], label: 'سرورها', min: 1, max: PHP_INT_MAX, most: self::SERVERS_MOST);
    }

    public function groups(): array
    {
        if (!CurrentBot::isMain()) {
            return [];
        }
        $fields = [$this->enabled];
        foreach (CustomQuantity::cases() as $quantity) {
            array_push($fields, $this->tiers[$quantity->value], $this->least[$quantity->value], $this->most[$quantity->value], $this->options[$quantity->value]);
        }

        return [new Form('custom_services', [...$fields, $this->devices, $this->servers])];
    }

    /** The switch as stored; the builder (CustomServices) adds the main-shop rule and what it needs to sell. */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }

    /** The quantity's price by tiers, as set. */
    public function tiers(CustomQuantity $quantity): PriceTiers
    {
        return new PriceTiers($this->settings->read($this->tiers[$quantity->value]));
    }

    /** @return array{int, int} The least and the most of the quantity a customer may build. */
    public function bounds(CustomQuantity $quantity): array
    {
        return [$this->settings->read($this->least[$quantity->value]), $this->settings->read($this->most[$quantity->value])];
    }

    /** @return list<int> The amounts of the quantity offered as buttons, smallest first. */
    public function options(CustomQuantity $quantity): array
    {
        return $this->settings->read($this->options[$quantity->value]);
    }

    /** Devices at once on a built service; 0 = unlimited. */
    public function devices(): int
    {
        return $this->settings->read($this->devices);
    }

    /** @return list<int> The servers a customer may build on, by id. */
    public function serverIds(): array
    {
        return $this->settings->read($this->servers);
    }
}
