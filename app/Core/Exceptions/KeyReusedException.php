<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * An Idempotency-Key sent again with another request: what the key did — a wallet's line, an agent's traffic line, a
 * ticket's answer — was something else. What the key did stands; another change takes a key of its own. (An order's key
 * has its own refusal: Orders\Exceptions\RequestKeyReusedException.)
 */
final class KeyReusedException extends DomainRuleException
{
    public const MESSAGE = 'این Idempotency-Key پیش‌تر برای درخواست دیگری به کار رفته است؛ برای هر تغییر کلید تازه‌ای بسازید.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    protected function field(): string
    {
        return 'idempotency_key';
    }
}
