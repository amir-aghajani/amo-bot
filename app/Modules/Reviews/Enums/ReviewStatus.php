<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Enums;

/**
 * Where a review stands (`reviews.status`): pending (waiting on support — the queue the panels count), approved (shown on
 * the website), or rejected (kept, never shown). Support may move an approved review to rejected and back.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';
}
