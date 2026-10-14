<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Ruled;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * «انتقال موجودی» (the `wallet_transfers` group, off by default): whether a customer may send money from their wallet to
 * another customer of the shop by their Telegram id, the least and the most one transfer moves, and the most a customer
 * sends in a day (0: no daily cap; else at least the least, or no transfer could be made). No fee.
 */
final class WalletTransferSettings implements DeclaresSettings
{
    /** A daily cap below the least one transfer moves. */
    public const DAILY_BELOW_MIN = 'سقف انتقال روزانه نمی‌تواند از کمترین مبلغ انتقال کمتر باشد؛ برای بدون سقف، صفر بگذارید.';

    private readonly Toggle $enabled;
    private readonly Number $min;
    private readonly Number $max;
    /** @var Field<int> */
    private readonly Field $daily;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('wallet_transfer_enabled', 'wallet.transfer_enabled', default: false);
        $this->min = new Number('wallet_transfer_min', 'wallet.transfer_min', default: 10_000, label: 'کمترین مبلغ انتقال', min: 1_000, max: WalletSettings::TOPUP_MAX, unit: 'تومان');
        $this->max = new Number('wallet_transfer_max', 'wallet.transfer_max', default: 5_000_000, label: 'بیشترین مبلغ انتقال', min: 1_000, max: WalletSettings::TOPUP_MAX, unit: 'تومان', atLeast: $this->min);
        $this->daily = new Ruled(
            new Number('wallet_transfer_daily', 'wallet.transfer_daily', default: 10_000_000, label: 'سقف انتقال روزانه هر مشتری', min: 0, max: WalletSettings::TOPUP_MAX, unit: 'تومان'),
            static fn(array $values): ?string => is_int($values['wallet_transfer_daily'] ?? null) && is_int($values['wallet_transfer_min'] ?? null)
                && $values['wallet_transfer_daily'] !== 0 && $values['wallet_transfer_daily'] < $values['wallet_transfer_min'] ? self::DAILY_BELOW_MIN : null,
        );
    }

    public function groups(): array
    {
        return [new Form('wallet_transfers', [$this->enabled, $this->min, $this->max, $this->daily])];
    }

    /** Whether customers may send money from their wallet to another customer. */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }

    /** @return array{int, int} The least and the most one transfer moves, in Toman. */
    public function bounds(): array
    {
        return [$this->settings->read($this->min), $this->settings->read($this->max)];
    }

    /** The most a customer sends in a day, in Toman; 0: no daily cap. */
    public function dailyCap(): int
    {
        return $this->settings->read($this->daily);
    }
}
