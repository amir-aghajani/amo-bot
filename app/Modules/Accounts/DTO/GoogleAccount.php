<?php

declare(strict_types=1);

namespace App\Modules\Accounts\DTO;

/** A Google account a sign-in proved (GoogleSignIn::account()): its `sub`, the address Google vouches for (or null), its names. */
final class GoogleAccount
{
    /** @param array{username: string|null, first_name: string|null, last_name: string|null} $profile */
    public function __construct(
        public readonly string $sub,
        public readonly ?string $email,
        public readonly array $profile,
    ) {}
}
