<?php

declare(strict_types=1);

namespace App\Modules\Agency\Enums;

enum AgencyRequestStatus: string
{
    case Pending = 'pending';   // waiting for support
    case Approved = 'approved'; // the customer became an agent
    case Rejected = 'rejected';
}
