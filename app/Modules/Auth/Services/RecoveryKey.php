<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Security\HostKey;

/**
 * The key of the panel's way back in (LoginRecovery): written to storage/recovery-key.txt as the sign-in page asks, read
 * by the owner off their host's files, good for an hour from when it was made, and gone once it has set a new login.
 */
final class RecoveryKey extends HostKey
{
    /** Where the owner finds it, from the shop's own folder. */
    public const FILE = 'storage/recovery-key.txt';

    /** An hour: long enough to open a file manager, too short to be left lying about. */
    public const LIFETIME = 3600;

    /** @param string $path The container's `recovery.key`: storage/recovery-key.txt */
    public function __construct(string $path)
    {
        parent::__construct($path, self::LIFETIME);
    }
}
