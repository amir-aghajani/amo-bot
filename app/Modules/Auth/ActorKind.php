<?php

declare(strict_types=1);

namespace App\Modules\Auth;

/** The kinds of Actor: the three principals, an admin in the report group, and the shop itself. */
enum ActorKind: string
{
    case Owner = 'owner';
    case Agent = 'agent';
    case Staff = 'staff';
    case GroupAdmin = 'group_admin';
    case System = 'system';
}
