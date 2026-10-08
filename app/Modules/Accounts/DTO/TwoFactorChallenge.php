<?php

declare(strict_types=1);

namespace App\Modules\Accounts\DTO;

/**
 * A password that was right for an account whose sign-in asks a second step: the challenge the code of its
 * authenticator app (or a recovery code) is posted with, and how long it waits — no session opened yet.
 */
final class TwoFactorChallenge
{
    public function __construct(
        public readonly string $challenge,
        public readonly int $expiresIn,
    ) {}
}
