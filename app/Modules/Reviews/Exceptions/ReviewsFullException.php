<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * The shop already has Services\Reviews::PENDING_MAX reviews waiting on support: no new one until support decides some.
 * A 503 worded for the customer, so a flood from address after address cannot grow the queue (or the report group)
 * without end.
 */
final class ReviewsFullException extends DomainRuleException
{
    public const MESSAGE = 'این فروشگاه الان نظر تازه‌ای نمی‌گیرد؛ کمی بعد دوباره امتحان کنید.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function status(): int
    {
        return 503;
    }
}
