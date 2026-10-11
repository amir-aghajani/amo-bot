<?php

declare(strict_types=1);

namespace App\Modules\Auth;

/**
 * The owner (config.php's login), an agent (signed in by the main bot's link), or a shop's admin (`users.role` admin,
 * signed in on the shop's website, working its admin API).
 */
enum PrincipalKind: string
{
    case Owner = 'owner';
    case Agent = 'agent';
    case Staff = 'staff';
}
