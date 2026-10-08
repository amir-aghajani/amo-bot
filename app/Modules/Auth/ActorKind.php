<?php

declare(strict_types=1);

namespace App\Modules\Auth;

/**
 * Who decided something (Actor): a principal of the panels or of a shop's website — the owner, an agent, one of the
 * shop's admins on its website —, one of the shop's admins pressing the report group's buttons or answering a ticket
 * there, or the shop itself — a task, a timer: nobody's name.
 */
enum ActorKind: string
{
    case Owner = 'owner';
    case Agent = 'agent';
    case Staff = 'staff';
    case GroupAdmin = 'group_admin';
    case System = 'system';
}
