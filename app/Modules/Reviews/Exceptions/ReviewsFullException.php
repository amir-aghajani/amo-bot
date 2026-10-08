<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * The shop keeps as many reviews waiting on support as it takes (Services\Reviews::PENDING_MAX): no new one until support
 * decides some — a 503 in the customer's words. Whoever writes them by the hundred, from address after address, fills
 * no moderation queue without end, nor the report group's.
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
