<?php

declare(strict_types=1);

namespace App\Modules\Agency\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * An agent's traffic pool does not hold what a delivery or an extension needs (or the plan is unlimited, which prepaid
 * traffic cannot pay for). The message becomes the failed order's notes, or the extension's refusal.
 */
final class TrafficShortException extends DomainRuleException
{
    /** What the plan needs, then what is left. */
    public const SHORT = 'حجم نمایندگی کافی نیست: این سفارش %s می‌خواهد و %s مانده است. پس از خرید حجم از ربات اصلی، تحویل را دوباره امتحان کنید.';

    /** What an extension of one service gives, then what is left. */
    public const EXTENSION = 'حجم نمایندگی کافی نیست: این افزایش %s می‌خواهد و %s مانده است؛ حجم بیشتر را از ربات اصلی بخرید.';

    /** What a free test account needs, then what is left. */
    public const TEST = 'حجم نمایندگی کافی نیست: اکانت تست %s می‌خواهد و %s مانده است.';

    public const UNLIMITED = 'پلن‌های حجم نامحدود در ربات نماینده فروخته نمی‌شوند؛ برای پلن حجم تعیین کنید.';
}
