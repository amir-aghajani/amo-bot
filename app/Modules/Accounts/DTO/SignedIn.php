<?php

declare(strict_types=1);

namespace App\Modules\Accounts\DTO;

use App\Modules\Accounts\Models\CustomerSession;

/** A sign-in that worked: the session it opened, and its bearer token — handed to the site once, kept nowhere. */
final class SignedIn
{
    public function __construct(
        public readonly string $token,
        public readonly CustomerSession $session,
    ) {}
}
