<?php

declare(strict_types=1);

namespace App\Core\Database\Drivers;

/**
 * A probe that failed: the database did not answer to the settings, or is one the shop cannot run on. The message is
 * the admin's, with the database's own error when there is one.
 */
final class ProbeFailedException extends \RuntimeException
{
    public static function missingExtension(string $extension): self
    {
        return new self("افزونه {$extension} روی PHP این سرور نصب نیست؛ از پشتیبانی هاست بخواهید آن را فعال کند.");
    }
}
