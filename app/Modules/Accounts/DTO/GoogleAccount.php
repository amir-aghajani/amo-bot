<?php

declare(strict_types=1);

namespace App\Modules\Accounts\DTO;

/**
 * The Google account a website's sign-in proved — its token checked the one way (Services\GoogleSignIn::account()): its
 * id (`sub`), the address Google vouches for (null when it does not), and its names (Users\Services\Customers::profile()).
 */
final class GoogleAccount
{
    /** @param array{username: string|null, first_name: string|null, last_name: string|null} $profile */
    public function __construct(
        public readonly string $sub,
        public readonly ?string $email,
        public readonly array $profile,
    ) {}
}
