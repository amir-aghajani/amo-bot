<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A running balance kept as lines (a wallet's `wallet_transactions`, an agent's `traffic_transactions`): each line
 * carries `balance_after`, so the last line is the balance and nothing else stores it. A line is written under a lock
 * on the owner's row, after reading the last line with a locking read — under MySQL's repeatable read a plain read
 * could see a snapshot older than the previous writer's line.
 *
 * That locking read locks the gap after the owner's last line too, so two owners' first lines at once (two newcomers'
 * top-ups) can deadlock: MySQL aborts one of the two transactions, whole — no money moves twice, but the request would
 * fail. A money transaction is therefore tried again, up to ATTEMPTS times, on a deadlock (Laravel's
 * `transaction($callback, $attempts)` retries only on a concurrency error, and only at the outermost transaction).
 */
final class Ledger
{
    /** The longest note an admin may give a line by hand; the `description` column has room to spare. */
    public const NOTE_MAX = 190;

    /**
     * How many times a money transaction is tried (append()'s own, and the outermost one of every money write around it)
     * when the database aborts it for a deadlock. Only a transaction whose callback does nothing but database work is
     * tried again — a message sent, a panel called or a file moved inside it would happen twice —, and only one that
     * reads what it moves inside the callback, or moves it by Transitions, which writes its move whatever the model
     * in memory holds.
     */
    public const ATTEMPTS = 3;

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Write the owner's next line: `$write` gets the balance now ("0" before the first line) and writes the line, or
     * throws to refuse — database work only: on its own (no caller's transaction) it is tried again on a deadlock
     * (ATTEMPTS). Runs in one transaction (the caller's, if any). An owner whose row is gone is a
     * ModelNotFoundException.
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
        }, self::ATTEMPTS);
    }

    /**
     * Fold `$from`'s lines into `$into`'s ledger (two accounts merged) and recount every `balance_after` in id order:
     * `$next` gets the balance before a line and the line, and returns the balance after it. Both owners' rows are
     * locked first, in id order, so an append() to either waits. Returns how many lines moved.
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
