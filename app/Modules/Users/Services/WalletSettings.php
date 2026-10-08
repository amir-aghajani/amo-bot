<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Numbers;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * The shop's wallet rules, as the admin sets them at runtime (the `wallet` group of the bot settings, «کیف پول»; every
 * bot its own): the smallest top-up and the amounts offered as buttons — what the bot's «افزایش موجودی», the website's
 * top-up and OrderService::topUpAllowed() go by —, below the largest a top-up may be.
 */
final class WalletSettings implements DeclaresSettings
{
    /** The largest top-up, in Toman. */
    public const TOPUP_MAX = 500_000_000;

    private readonly Number $topUpMin;
    private readonly Numbers $topUpPresets;

    public function __construct(private readonly Settings $settings)
    {
        $this->topUpMin = new Number('topup_min', 'bot.topup_min', default: 10_000, label: 'حداقل شارژ', min: 1_000, max: self::TOPUP_MAX, unit: 'تومان');
        $this->topUpPresets = new Numbers('topup_presets', 'bot.topup_presets', default: [50_000, 100_000, 200_000, 500_000], label: 'مبلغ‌های پیشنهادی', min: 1, max: self::TOPUP_MAX, most: 8, unit: 'تومان', atLeast: $this->topUpMin);
    }

    public function groups(): array
    {
        return [new Form('wallet', [$this->topUpMin, $this->topUpPresets])];
    }

    /** The smallest top-up the shop takes, in Toman. */
    public function topUpMin(): int
    {
        return $this->settings->read($this->topUpMin);
    }

    /** @return list<int> The amounts offered as buttons on the top-up screen, ascending. */
    public function topUpPresets(): array
    {
        return $this->settings->read($this->topUpPresets);
    }
}
