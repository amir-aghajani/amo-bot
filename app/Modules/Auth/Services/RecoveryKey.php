<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Security\HostKey;

/** LoginRecovery's one-time key in storage/recovery-key.txt: valid for an hour, deleted once used. */
final class RecoveryKey extends HostKey
{
    /** Where the owner finds it, relative to the app's folder. */
    public const FILE = 'storage/recovery-key.txt';

    /** An hour: long enough to open a file manager, too short to be left lying about. */
    public const LIFETIME = 3600;

    /** @param string $path The container's `recovery.key`: storage/recovery-key.txt */
    public function __construct(string $path)
    {
        parent::__construct($path, self::LIFETIME);
    }
}
