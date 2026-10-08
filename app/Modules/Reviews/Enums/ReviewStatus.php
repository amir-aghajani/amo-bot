<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Enums;

/**
 * Where a customer's review stands (`reviews.status`): pending — waiting on support (the queue the panels count) —,
 * approved — shown on the shop's website —, or rejected — kept, never shown. Support moves it either way: an approved one
 * may be rejected (hidden) later, a rejected one approved (Reviews\Services\Reviews).
 */
enum ReviewStatus: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';
}
