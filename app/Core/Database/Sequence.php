<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Database\ConnectionInterface;

/**
 * Named counters that only go up (`sequences` table — the number after a panel client's name). `next()` is a locked
 * read-and-increment, so two processes never draw the same value; a value drawn is spent even when the thing it was
 * for fails, so gaps are normal.
 */
final class Sequence
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** The counter's next value: 1 the first time a name is drawn. */
    public function next(string $name): int
    {
        // Outside the transaction: an ignored duplicate insert must not hold a lock the FOR UPDATE below waits on.
        $this->db->table('sequences')->insertOrIgnore(['name' => $name, 'value' => 0]);

        return $this->db->transaction(function () use ($name): int {
            $value = (int) $this->db->table('sequences')->where('name', $name)->lockForUpdate()->value('value') + 1;
            $this->db->table('sequences')->where('name', $name)->update(['value' => $value]);

            return $value;
        });
    }
}
