<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Outside;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Form;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Enums\GatewayKind;

/**
 * Money support took outside the shop — cash, a card the shop does not list — for an order it made for a customer
 * (Orders\Services\ManualOrders): settled at once, and counted in the shop's takings (Payment::moneyIn()). Built in —
 * every shop has exactly one row (PaymentMethods::createBuiltins()) — with nothing to set, and support's alone: never
 * offered at checkout nor listed among the payment methods (PaymentMethods::offers(), all()).
 */
final class OutsideDriver implements GatewayDriver
{
    public function key(): string
    {
        return OutsideGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: OutsideGateway::label(),
            description: 'پولی که پشتیبانی بیرون از فروشگاه گرفته است (نقدی یا کارت دیگر) برای سفارشی که خودش برای مشتری ثبت می‌کند؛ هیچ‌وقت به مشتری نشان داده نمی‌شود.',
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
        return new OutsideGateway();
    }
}
