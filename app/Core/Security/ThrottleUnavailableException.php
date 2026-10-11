<?php

declare(strict_types=1);

namespace App\Core\Security;

/** The throttle's folder is not writable, so it lets nobody try; the message says which folder to fix. */
final class ThrottleUnavailableException extends \RuntimeException
{
    public function __construct(string $directory)
    {
        parent::__construct("The throttle cannot keep count in {$directory}: what it counts — sign-ins, the panels' error reports — is refused until the web server may write there.");
    }
}
