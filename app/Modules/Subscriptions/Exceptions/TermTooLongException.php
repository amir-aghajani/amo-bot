<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Support\LocalTime;

/**
 * A purchase, a renewal, extra time or days given whose term would end the service past the last day the shop keeps
 * (LocalTime::LAST_DAY: its TIMESTAMP columns end in January 2038) — refused before anything is charged or sent to a
 * panel (Services\ServiceTerms). A 422; a delivery fails with it, for support to refund.
 */
final class TermTooLongException extends DomainRuleException
{
    public function __construct()
    {
        parent::__construct(self::message());
    }

    /** «تاریخ پایان سرویس نمی‌تواند بعد از ۲۸ دی ۱۴۱۶ باشد…», for a form that refuses it under its own field. */
    public static function message(): string
    {
        return LocalTime::tooLateFor('تاریخ پایان سرویس') . '؛ مدت کوتاه‌تری انتخاب کنید.';
    }
}
