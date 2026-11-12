<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use Illuminate\Database\Connection;

/**
 * Work done with the foreign keys out of the way — a restore deletes and inserts a shop's rows table by table, and its
 * own rules (Restorer) keep what the keys would: MySQL and MariaDB check none for the connection while it runs; SQLite,
 * which cannot stop checking inside a transaction, checks at the commit instead (defer_foreign_keys). There a delete
 * still applies its key's ON DELETE rule (deleteActs()): the restore puts back what that took (Restorer).
 */
final class ForeignKeys
{
    /**
     * Whether a delete in work without() is about to run still applies its key's ON DELETE rule: on SQLite inside a
     * transaction, whose keys can only be deferred — asked before without() starts.
     */
    public static function deleteActs(Connection $db): bool
    {
        return $db->getDriverName() === 'sqlite' && $db->transactionLevel() > 0;
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function without(Connection $db, \Closure $work): mixed
    {
        if ($db->getDriverName() === 'sqlite') {
            if ($db->transactionLevel() > 0) {
                $db->statement('PRAGMA defer_foreign_keys = ON');

                return $work();
            }
            $db->statement('PRAGMA foreign_keys = OFF');
            try {
                return $work();
            } finally {
                $db->statement('PRAGMA foreign_keys = ON');
            }
        }

        $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            return $work();
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
