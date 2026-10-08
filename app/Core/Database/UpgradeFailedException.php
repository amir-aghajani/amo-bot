<?php

declare(strict_types=1);

namespace App\Core\Database;

/** A database upgrade (Upgrades) that did not go through: which one, and why — the ones before it ran. */
final class UpgradeFailedException extends \RuntimeException
{
    public function __construct(
        /** The version the upgrade would have brought the database to. */
        public readonly string $version,
        \Throwable $previous,
    ) {
        parent::__construct("The database upgrade to {$version} failed: {$previous->getMessage()}", 0, $previous);
    }
}
