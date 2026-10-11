<?php

declare(strict_types=1);

namespace App\Modules\Installer\Services;

use App\Core\Security\HostKey;

/**
 * The installer's one-time key (storage/install-key.txt). A fresh upload would answer its installer to whoever comes
 * first; with the key, only someone who can read the host's files can install the shop. Deleted when installation ends.
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
