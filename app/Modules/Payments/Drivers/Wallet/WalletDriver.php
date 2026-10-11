<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Wallet;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Form;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Enums\GatewayKind;
use App\Modules\Users\Services\WalletService;

/**
 * The wallet: pays from the customer's balance and settles at once. Built in — every shop has exactly one row
 * (PaymentMethods::createBuiltins()) — with nothing to set but its switch.
 */
final class WalletDriver implements GatewayDriver
{
    public function __construct(private readonly WalletService $wallet) {}

    public function key(): string
    {
        return WalletGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: WalletGateway::label(),
            description: 'پرداخت از موجودی کیف پول مشتری؛ بلافاصله تسویه و تحویل می‌شود. شارژ کیف پول با روش‌های دیگر انجام می‌شود.',
            form: new Form($this->key(), []),
            traits: [self::KIND => GatewayKind::Instant->value, self::BUILTIN => true],
        );
    }

    public function summary(array $settings): ?string
    {
        return null;
    }

    public function gateway(array $settings): GatewayInterface
    {
        return new WalletGateway($this->wallet);
    }
}
