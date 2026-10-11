<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Payments\Models\PaymentMethod;
use App\Support\Persian;

/** A method payments were made with cannot be deleted (each payment reads its driver and card through it): switch it off. */
final class MethodInUseException extends DomainRuleException
{
    public function __construct(PaymentMethod $method, int $payments)
    {
        parent::__construct(sprintf('با «%s» %s پرداخت انجام شده و برای حفظ تاریخچه قابل حذف نیست؛ به‌جای حذف، آن را غیرفعال کنید.', $method->label, Persian::number($payments)));
    }

    public function status(): int
    {
        return 409;
    }
}
