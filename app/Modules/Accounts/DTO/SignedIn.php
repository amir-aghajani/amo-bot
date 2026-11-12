<?php

declare(strict_types=1);

namespace App\Modules\Accounts\DTO;

use App\Modules\Accounts\Models\CustomerSession;

/**
 * A sign-in that worked: the session it opened and its bearer token, handed to the site once and kept nowhere — and, a
 * sign-in by a code texted to a number another account of the shop holds too, the merge offered with it
 * (Services\PhoneSignIn::mergeOffer()).
 */
final class SignedIn
{
    public function __construct(
        public readonly string $token,
        public readonly CustomerSession $session,
        public readonly ?MergeOffer $merge = null,
    ) {}
}
