<?php

declare(strict_types=1);

namespace App\Modules\Agency\Enums;

/** What an agency request asks for (`agency_requests.kind`): to become an agent, or an agent's end moved on. */
enum AgencyRequestKind: string
{
    case Join = 'join';       // a customer asks to become an agent
    case Renewal = 'renewal'; // an agent asks for their agency's end to be moved on
}
