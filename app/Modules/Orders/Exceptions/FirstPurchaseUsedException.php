<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A purchase at the first-purchase price whose delivery found another purchase of the customer's paid before it: two
 * were ordered at that price before either was paid, and only the first one paid is the first purchase
 * (OrderService::deliverPurchase()). The message becomes the order's notes, telling support to give the money back.
 */
final class FirstPurchaseUsedException extends DomainRuleException
{
    public const MESSAGE = 'این سفارش با قیمت خرید اول ثبت شده بود، ولی خرید دیگری از همین مشتری زودتر پرداخت شد؛ مبلغ آن را به مشتری برگردانید.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
