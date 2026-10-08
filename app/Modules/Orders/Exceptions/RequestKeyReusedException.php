<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A request's key (the website's `Idempotency-Key`, `request_keys`) came to an order of something else before: another
 * type, plan, server or service — or a top-up of another amount (MESSAGE); or the request sent with it first asked for
 * another way to pay (OTHER_METHOD: made again with this one, the same order would be paid twice over, or another way). A
 * price changed since is no other thing: the order the key came to answers it (OrderService::open()). A key stands for
 * one checkout attempt; the request is refused rather than answered with that other order — or a second one made.
 */
final class RequestKeyReusedException extends DomainRuleException
{
    public const MESSAGE = 'این Idempotency-Key پیش‌تر برای سفارش دیگری به کار رفته است.';
    public const OTHER_METHOD = 'این Idempotency-Key پیش‌تر با روش پرداخت دیگری به کار رفته است؛ برای پرداخت به روش دیگر کلید تازه‌ای بسازید.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }

    /** The key's request asked for another way to pay. */
    public static function otherMethod(): self
    {
        return new self(self::OTHER_METHOD);
    }

    protected function field(): string
    {
        return 'idempotency_key';
    }
}
