<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A renewal or an extra whose delivery found its service handed to another customer since it was ordered (support's
 * transfer leaves the giver's orders paid or with a receipt in review): what they paid for is not added to someone
 * else's service (Models\Order::serviceChangedHands()). The message becomes the order's notes, telling support to give
 * the money back.
 */
final class ServiceChangedHandsException extends DomainRuleException
{
    public const MESSAGE = 'سرویس این سفارش پیش از تحویل به حساب دیگری منتقل شد و چیزی به آن اضافه نشد؛ مبلغ آن را به مشتری برگردانید.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
