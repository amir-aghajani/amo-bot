<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Numbers;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * The wallet settings (the `wallet` group): the smallest top-up and the amounts offered as buttons, up to TOPUP_MAX
 * (OrderService::topUpAllowed() enforces them for the bot and the website); buttons of amounts drawn at random within a
 * range, rounded; and a small random sum added to each card-to-card top-up, so every transfer is a different amount and
 * its receipt is told apart at a glance (0: none).
 */
final class WalletSettings implements DeclaresSettings
{
    /** The largest top-up, in Toman. */
    public const TOPUP_MAX = 500_000_000;

    /** The most random-amount buttons, and the largest random sum added to a card-to-card top-up (Toman). */
    public const RANDOM_BUTTONS_MOST = 8;
    public const UNIQUE_MAX = 9_999;

    private readonly Number $topUpMin;
    private readonly Numbers $topUpPresets;
    private readonly Number $randomCount;
    private readonly Number $randomMin;
    private readonly Number $randomMax;
    private readonly Number $randomRound;
    private readonly Number $uniqueMax;

    public function __construct(private readonly Settings $settings)
    {
        $this->topUpMin = new Number('topup_min', 'bot.topup_min', default: 10_000, label: 'حداقل شارژ', min: 1_000, max: self::TOPUP_MAX, unit: 'تومان');
        $this->topUpPresets = new Numbers('topup_presets', 'bot.topup_presets', default: [50_000, 100_000, 200_000, 500_000], label: 'مبلغ‌های پیشنهادی', min: 1, max: self::TOPUP_MAX, most: 8, unit: 'تومان', atLeast: $this->topUpMin);
        $this->randomCount = new Number('topup_random_count', 'bot.topup_random_count', default: 0, label: 'تعداد دکمه‌های مبلغ تصادفی', min: 0, max: self::RANDOM_BUTTONS_MOST);
        $this->randomMin = new Number('topup_random_min', 'bot.topup_random_min', default: 50_000, label: 'کمترین مبلغ تصادفی', min: 1_000, max: self::TOPUP_MAX, unit: 'تومان', atLeast: $this->topUpMin);
        $this->randomMax = new Number('topup_random_max', 'bot.topup_random_max', default: 500_000, label: 'بیشترین مبلغ تصادفی', min: 1_000, max: self::TOPUP_MAX, unit: 'تومان', atLeast: $this->randomMin);
        $this->randomRound = new Number('topup_random_round', 'bot.topup_random_round', default: 1_000, label: 'گرد کردن مبلغ تصادفی', min: 1, max: 1_000_000, unit: 'تومان');
        $this->uniqueMax = new Number('topup_unique_max', 'bot.topup_unique_max', default: 0, label: 'مبلغ تصادفی افزوده به شارژ کارت‌به‌کارت', min: 0, max: self::UNIQUE_MAX, unit: 'تومان');
    }

    public function groups(): array
    {
        return [new Form('wallet', [$this->topUpMin, $this->topUpPresets, $this->randomCount, $this->randomMin, $this->randomMax, $this->randomRound, $this->uniqueMax])];
    }

    /**
     * The random-amount buttons of the top-up screen: how many (0: none), drawn within min..max, each rounded to `round`
     * Toman.
     *
     * @return array{count: int, min: int, max: int, round: int}
     */
    public function randomButtons(): array
    {
        return [
            'count' => $this->settings->read($this->randomCount),
            'min' => $this->settings->read($this->randomMin),
            'max' => $this->settings->read($this->randomMax),
            'round' => $this->settings->read($this->randomRound),
        ];
    }

    /** The most a card-to-card top-up gets added at random (1..this many Toman) to tell its receipt apart; 0: nothing. */
    public function uniqueMax(): int
    {
        return $this->settings->read($this->uniqueMax);
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
