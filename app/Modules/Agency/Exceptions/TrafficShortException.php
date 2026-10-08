<?php

declare(strict_types=1);

namespace App\Modules\Agency\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * An agent's bot cannot deliver: its traffic pool does not have what the plan sells (or the plan sells unlimited
 * traffic, which a pool cannot pay for) — or what the shop would give one of its services. Its message is Persian: it
 * becomes the failed order's notes, or the refusal of the extension.
 */
final class TrafficShortException extends DomainRuleException
{
    /** What the plan needs, then what is left. */
    public const SHORT = 'حجم نمایندگی کافی نیست: این سفارش %s می‌خواهد و %s مانده است. پس از خرید حجم از ربات اصلی، تحویل را دوباره امتحان کنید.';

    /** What an extension of one service gives, then what is left. */
    public const EXTENSION = 'حجم نمایندگی کافی نیست: این افزایش %s می‌خواهد و %s مانده است؛ حجم بیشتر را از ربات اصلی بخرید.';

    public const UNLIMITED = 'پلن‌های حجم نامحدود در ربات نماینده فروخته نمی‌شوند؛ برای پلن حجم تعیین کنید.';
}
