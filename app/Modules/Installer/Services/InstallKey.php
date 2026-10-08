<?php

declare(strict_types=1);

namespace App\Modules\Installer\Services;

use App\Core\Security\HostKey;

/**
 * The installer's one-time key. A fresh upload answers its installer to whoever reaches it first; with the key, only
 * someone who can read the host's files can install the shop as theirs. It is made the first time the installer is
 * asked for (storage/install-key.txt), every /api/install request carries it (`X-Install-Key`, InstallKeyMiddleware),
 * and it is gone once the installation ends.
 */
final class InstallKey extends HostKey
{
    public const HEADER = 'X-Install-Key';

    /** @param string $path The container's `install.key`: storage/install-key.txt */
    public function __construct(string $path)
    {
        parent::__construct($path);
    }
}
