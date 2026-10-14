<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;

/**
 * The way to pay picked cannot take this amount now: it is outside the method's bounds, or none of a card method's cards
 * has room for it today (their daily caps). Nothing was paid; the customer picks another way.
 */
final class MethodUnavailableException extends DomainRuleException
{
    /** The amount is outside what the method is offered for. */
    public static function outOfBounds(PaymentMethod $method): self
    {
        return new self(sprintf('«%s» %s؛ روش دیگری انتخاب کنید.', $method->label, PaymentMethods::boundsInWords($method)));
    }

    /** Every card of the method took what it may today (or none is switched on). */
    public static function noCard(PaymentMethod $method): self
    {
        return new self(sprintf('«%s» امروز برای این مبلغ در دسترس نیست؛ روش دیگری انتخاب کنید.', $method->label));
    }

    protected function field(): string
    {
        return 'method_id';
    }
}
