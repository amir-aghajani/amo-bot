<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Modules\Orders\Exceptions\FirstPurchaseUsedException;
use App\Modules\Orders\Exceptions\ServiceChangedHandsException;
use App\Modules\Orders\Models\Order;

/**
 * Why a paid order failed, as its customer is told (Telegram\Notifications\ServiceCard::failedText(), the website's
 * `failure`): no longer their first purchase by the time it was paid, or its service handed to someone else since it
 * was ordered — never delivered, support settles its payment with them —, else its delivery failed and support finishes
 * it. Read off the order's notes, the refusal's own words (each exception's failedSo()).
 */
enum OrderFailure: string
{
    case FirstPurchaseUsed = 'first_purchase_used';
    case ServiceChangedHands = 'service_changed_hands';
    case Delivery = 'delivery';

    /** Why the order failed; null for one that did not. */
    public static function of(Order $order): ?self
    {
        return match (true) {
            $order->status !== OrderStatus::Failed => null,
            FirstPurchaseUsedException::failedSo($order) => self::FirstPurchaseUsed,
            ServiceChangedHandsException::failedSo($order) => self::ServiceChangedHands,
            default => self::Delivery,
        };
    }
}
