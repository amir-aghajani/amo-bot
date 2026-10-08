<?php

declare(strict_types=1);

namespace App\Modules\Providers\Enums;

/** How the shop signs in to a panel: with an API token, or with an admin's username and password. */
enum AuthMode: string
{
    case Token = 'token';
    case Password = 'password';
}
