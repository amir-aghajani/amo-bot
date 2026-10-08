<?php

declare(strict_types=1);

namespace App\Modules\Auth;

/**
 * Who a principal is (Principal): the owner, signed in to their panel with the login config.php keeps; an agent, signed
 * in to theirs with the main bot's link; or one of a shop's admins — its bot's (`users.role` admin) — signed in on the
 * shop's website with their bearer token, working the shop through its admin API (Store\Http\StaffMiddleware).
 */
enum PrincipalKind: string
{
    case Owner = 'owner';
    case Agent = 'agent';
    case Staff = 'staff';
}
