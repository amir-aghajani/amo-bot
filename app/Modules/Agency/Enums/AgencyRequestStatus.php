<?php

declare(strict_types=1);

namespace App\Modules\Agency\Enums;

/** Where a customer's request to become an agent stands; a verdict is a compare-and-swap from pending. */
enum AgencyRequestStatus: string
{
    case Pending = 'pending';   // waiting for support
    case Approved = 'approved'; // the customer became an agent
    case Rejected = 'rejected';
}
