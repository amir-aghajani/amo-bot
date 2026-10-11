<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * An Idempotency-Key reused for a different request: its order buys something else (another type, plan, server, service
 * or top-up amount — MESSAGE), or the key was first sent with another payment method (OTHER_METHOD). A changed price is
 * not a different request: the key's own order answers it (OrderService::open()).
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
