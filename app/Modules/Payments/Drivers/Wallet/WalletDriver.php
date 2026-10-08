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
 * The wallet: an order paid from the customer's balance, settled at once. Part of the shop itself — every shop has its
 * one row (database/schema.php's starting row; an agent's shop's, PaymentMethods::createBuiltins()), nothing to set but
 * its switch: no field. Its gateway is WalletGateway.
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
