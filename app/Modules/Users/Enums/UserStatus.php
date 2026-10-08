<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Banned = 'banned';
}
