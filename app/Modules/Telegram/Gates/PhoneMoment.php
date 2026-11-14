<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Gates;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Payments\Drivers\Manual\ManualGateway;
use App\Modules\Payments\Models\PaymentMethod;

/**
 * A moment the shop may ask a customer's number at, instead of before anything at all (PhoneRequirement): a service
 * bought, a service renewed (or given more traffic, time or devices), a free test taken, a card-to-card payment.
 */
enum PhoneMoment: string
{
    case Purchase = 'purchase';
    case Renewal = 'renewal';
    case Test = 'test';
    case Card = 'card';

    /**
     * The moments an order of `$type` paid with `$method` is: a purchase or a renewal (an extra counts as one), and a
     * card-to-card payment whatever it pays for — a wallet top-up is only that.
     *
     * @return list<self>
     */
    public static function ofOrder(OrderType $type, ?PaymentMethod $method): array
    {
        $moments = match ($type) {
            OrderType::Purchase => [self::Purchase],
            OrderType::Renewal, OrderType::ExtraTraffic, OrderType::ExtraTime, OrderType::ExtraDevices => [self::Renewal],
            default => [],
        };

        return $method !== null && $method->driver === ManualGateway::key() ? [...$moments, self::Card] : $moments;
    }
}
