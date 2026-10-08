<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A running balance kept as lines — a customer's wallet (`wallet_transactions`), an agent's traffic
 * (`traffic_transactions`): every line carries the balance from then on, so the last line's is the balance now and
 * nothing else stores it. What makes that safe is how a line is written, the same for every ledger: under a lock on the
 * owner's row (two writers take turns), the last line read with a locking read (under MySQL's repeatable read a plain
 * read could answer from a snapshot taken before the lock, older than the line the turn before wrote), the new line
 * written — in one transaction, the caller's when there is one. Two owners become one the same way (merge()): both
 * rows locked, one owner's lines the other's, every balance counted again.
 */
final class Ledger
{
    /** The longest note an admin gives a line by hand (a wallet's, a traffic's): within the `description` column with room to spare. */
    public const NOTE_MAX = 190;

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Write the owner's next line: `$write` is given the balance now — the last line's `balance_after`, "0" before the
     * first — and writes the line, returns without one, or refuses by throwing, which writes nothing and reaches the
     * caller as thrown. An owner whose row is gone is a ModelNotFoundException, before `$write` is asked.
     *
     * @template T
     * @param string $lines The ledger's table
     * @param string $ownerColumn The column of `$lines` that names the owner (`user_id`, `bot_id`)
     * @param \Closure(string): T $write
     * @return T
     */
    public function append(Model $owner, string $lines, string $ownerColumn, \Closure $write): mixed
    {
        return $this->db->transaction(function () use ($owner, $lines, $ownerColumn, $write): mixed {
            $locked = $this->db->table($owner->getTable())->where($owner->getKeyName(), $owner->getKey())->lockForUpdate()->value($owner->getKeyName());
            if ($locked === null) {
                throw (new ModelNotFoundException())->setModel($owner::class, [$owner->getKey()]);
            }

            $last = $this->db->table($lines)->where($ownerColumn, $owner->getKey())->orderByDesc('id')->limit(1)->lockForUpdate()->value('balance_after');

            return $write(is_numeric($last) ? (string) $last : '0');
        });
    }

    /**
     * Fold `$from`'s ledger into `$into`'s — two owners become one (two accounts of a customer merged): every line of
     * `$from` becomes `$into`'s, and the balance on every line of the ledger they make is counted again from nothing, line
     * by line in the order they were written (id) — `$next` is given the balance before a line and the line, and answers
     * the balance after it —, so its last line is the two balances together and every line agrees with the ones before
     * it. Both owners' rows are locked first, in id order (an append() to either waits for the merge, and the merge for it),
     * the lines read with a locking read; one transaction, the caller's when there is one. How many lines moved.
     *
     * @param \Closure(string, \stdClass): string $next
     */
    public function merge(Model $into, Model $from, string $lines, string $ownerColumn, \Closure $next): int
    {
        return $this->db->transaction(function () use ($into, $from, $lines, $ownerColumn, $next): int {
            $key = $into->getKeyName();
            $this->db->table($into->getTable())->whereIn($key, [$into->getKey(), $from->getKey()])->orderBy($key)->lockForUpdate()->pluck($key);

            $moved = $this->db->table($lines)->where($ownerColumn, $from->getKey())->update([$ownerColumn => $into->getKey()]);

            $balance = '0';
            foreach ($this->db->table($lines)->where($ownerColumn, $into->getKey())->orderBy('id')->lockForUpdate()->get() as $line) {
                $balance = $next($balance, $line);
                if ((string) $line->balance_after !== $balance) {
                    $this->db->table($lines)->where('id', $line->id)->update(['balance_after' => $balance]);
                }
            }

            return $moved;
        });
    }
}
