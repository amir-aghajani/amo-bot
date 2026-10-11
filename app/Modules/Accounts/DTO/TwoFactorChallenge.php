<?php

declare(strict_types=1);

namespace App\Modules\Accounts\DTO;

/** A right password for an account with two-factor sign-in: the challenge its code is posted with, and how long it waits. No session yet. */
final class TwoFactorChallenge
{
    public function __construct(
        public readonly string $challenge,
        public readonly int $expiresIn,
    ) {}
}
