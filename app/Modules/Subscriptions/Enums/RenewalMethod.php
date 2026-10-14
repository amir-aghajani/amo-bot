<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/**
 * How a renewal adds its package to a service (Services\ServiceTerms::renewal()) — the shop offers the ones its admin
 * switches on (Services\RenewalSettings), and the customer picks among them when there is more than one. Kept on the
 * renewal order (`orders.renewal_method`), so its delivery adds what its checkout showed.
 */
enum RenewalMethod: string
{
    /** «تمدید پلن فعلی»: the term on top of what is left; the traffic as the shop carries unused traffic or not. */
    case Extend = 'extend';
    /** «ریست حجم + افزایش روز»: the term on top of what is left; the traffic starts afresh, what was left is lost. */
    case ResetTraffic = 'reset_traffic';
    /** «ریست زمان + انتقال حجم»: the term starts afresh, what was left of it is lost; the traffic left carries over. */
    case ResetTime = 'reset_time';

    public function label(): string
    {
        return match ($this) {
            self::Extend => 'تمدید پلن فعلی',
            self::ResetTraffic => 'ریست حجم + افزایش روز',
            self::ResetTime => 'ریست زمان + انتقال حجم',
        };
    }
}
