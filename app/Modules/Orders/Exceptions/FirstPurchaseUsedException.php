<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;

/**
 * An order made for the customer's first purchase — at the first-purchase price, or with a code for a first purchase
 * only — whose delivery found another purchase of theirs paid before it: two were ordered so before either was paid,
 * and only the first one paid is the first purchase (OrderService::assertFirstPurchase()). The message becomes the
 * order's notes, telling support to give the money back; the customer is told so in words of their own (failedSo()).
 */
final class FirstPurchaseUsedException extends DomainRuleException
{
    public const MESSAGE = 'این سفارش با قیمت خرید اول ثبت شده بود، ولی خرید دیگری از همین مشتری زودتر پرداخت شد؛ مبلغ آن را به مشتری برگردانید.';

    /** The same for an order made with a code for a first purchase only, by the code's text. */
    public const CODE = 'این سفارش با کد تخفیف خرید اول (%s) ثبت شده بود، ولی خرید دیگری از همین مشتری زودتر پرداخت شد؛ مبلغ آن را به مشتری برگردانید.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }

    /** An order made with the first-purchase-only code `$code`. */
    public static function code(string $code): self
    {
        return new self(sprintf(self::CODE, $code));
    }

    /** Whether the order failed for this refusal: failed, its notes this refusal's words (by the price or a code). */
    public static function failedSo(Order $order): bool
    {
        [$before, $after] = explode('%s', self::CODE, 2);
        $notes = (string) $order->notes;

        return $order->status === OrderStatus::Failed
            && ($notes === self::MESSAGE || (str_starts_with($notes, $before) && str_ends_with($notes, $after)));
    }
}
