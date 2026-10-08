<?php

declare(strict_types=1);

namespace App\Core\Database\Drivers;

/**
 * A database that did not answer to a driver's settings, or answered as one the shop cannot run on — said in the
 * admin's words, with the database's own when it gave any. Nothing is written to config.php after one.
 */
final class ProbeFailedException extends \RuntimeException
{
    public static function missingExtension(string $extension): self
    {
        return new self("افزونه {$extension} روی PHP این سرور نصب نیست؛ از پشتیبانی هاست بخواهید آن را فعال کند.");
    }
}
