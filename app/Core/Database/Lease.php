<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A row taken for a while by the one process working through it — a broadcast's run, a grant's part, a service a change
 * is made to on its panel: whoever holds the lease (its token in `lease_token`, until `leased_until`) is the only one
 * that moves the row's work on, and a holder that goes quiet loses it once the time runs out, so the work is neither
 * stuck with a dead worker nor done twice.
 *
 * Every write is one conditional UPDATE. Moving the work on — write(), increment(), finish(), and renew() before a long
 * step — needs the token and the row still in the state the lease was taken in (`$while`: a status), and holds it
 * `$seconds` longer: a pause, a cancel or a takeover stops the holder at its next step instead of letting it act.
 * Undoing its own last step (move()) and letting go (release()) need the token alone: a pause that came between a step
 * and its undo must not skip the item, and a paused row is let go at once, so a resume does not wait for the time to run
 * out.
 *
 * A leased table carries the two columns: `lease_token` (string, 32) and `leased_until` (nullable timestamp). After a
 * write that went through, the model holds the values written.
 */
final class Lease
{
    public const TOKEN = 'lease_token';
    public const UNTIL = 'leased_until';

    /** A lease cleared: what a stop from outside (a cancel) writes with its state, so whoever held the row stops. */
    public const FREE = [self::TOKEN => null, self::UNTIL => null];

    /** @param (\Closure(Builder<Model>): mixed)|null $while */
    private function __construct(
        private readonly Model $row,
        private readonly ?\Closure $while,
        public readonly string $token,
        private readonly int $seconds,
    ) {}

    /**
     * Take the row for `$seconds` when nobody holds it, or its holder let the time run out: the lease, or null when
     * someone else has it or the row is not in the state `$while` asks for.
     *
     * @param (\Closure(Builder<Model>): mixed)|null $while What the row must be to be worked on (its status) — asked
     *                                                      again by every step
     */
    public static function take(Model $row, int $seconds, ?\Closure $while = null): ?self
    {
        $lease = new self($row, $while, bin2hex(random_bytes(16)), $seconds);

        return $lease->apply(self::whereFree($lease->workable()), [self::TOKEN => $lease->token] + $lease->extension()) ? $lease : null;
    }

    /**
     * `$query` narrowed to rows nobody holds: no lease, or one whose time ran out — what take() takes, and what a write
     * of no holder's may go over (a read of the row's panel that must not cross a change under way).
     *
     * @template TModel of Model
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    public static function whereFree(Builder $query): Builder
    {
        return $query->where(static fn(Builder $free) => $free->whereNull(self::UNTIL)->orWhere(self::UNTIL, '<=', now()));
    }

    /**
     * Write `$values` — the step that moves the work on, a cursor onto the next item before the item is touched. False
     * when the row is no longer this lease's or no longer workable: nothing was written, and the caller stops.
     *
     * @param array<string, mixed> $values
     */
    public function write(array $values): bool
    {
        return $this->apply($this->workable()->where(self::TOKEN, $this->token), $values + $this->extension());
    }

    /**
     * Hold the row `$seconds` longer, writing nothing else — before a step that takes a while (a call to a panel), so a
     * holder still at work never loses it to another. False as write() is: the row is no longer this lease's.
     */
    public function renew(): bool
    {
        return $this->write([]);
    }

    /**
     * One more under `$column` — a tally — with `$values` written in the same statement; false as write() is.
     *
     * @param array<string, mixed> $values
     */
    public function increment(string $column, array $values = []): bool
    {
        $values += $this->extension();
        if ($this->workable()->where(self::TOKEN, $this->token)->increment($column, 1, $values) !== 1) {
            return false;
        }
        $this->sync([$column => (int) $this->row->getAttribute($column) + 1] + $values);

        return true;
    }

    /**
     * The work is through: `$values` (its end — a status) written and the row let go in one statement. False as
     * write() is: someone else's decision (a cancel) stands.
     *
     * @param array<string, mixed> $values
     */
    public function finish(array $values): bool
    {
        return $this->apply($this->workable()->where(self::TOKEN, $this->token), $values + self::FREE);
    }

    /**
     * Put `$column` back from `$from` to `$to` — a cursor returned to an item that must be done again (Telegram asked
     * for a pause, a panel was out of reach) — while this lease holds the row and nobody moved it on meanwhile.
     */
    public function move(string $column, int|string $from, int|string $to): bool
    {
        return $this->apply($this->row()->where(self::TOKEN, $this->token)->where($column, $from), [$column => $to]);
    }

    /** Let the row go, whatever state it is in now; true when it was still this lease's. Safe to call again. */
    public function release(): bool
    {
        return $this->apply($this->row()->where(self::TOKEN, $this->token), self::FREE);
    }

    /** @return Builder<Model> */
    private function row(): Builder
    {
        return $this->row->newModelQuery()->whereKey($this->row->getKey());
    }

    /** @return Builder<Model> The row, in the state the lease was taken in. */
    private function workable(): Builder
    {
        $query = $this->row();
        if ($this->while !== null) {
            ($this->while)($query);
        }

        return $query;
    }

    /** @return array{leased_until: \Illuminate\Support\Carbon} */
    private function extension(): array
    {
        return [self::UNTIL => now()->addSeconds($this->seconds)];
    }

    /**
     * One conditional UPDATE: true when it found the row. Every database the shop runs on reports the rows an UPDATE
     * matched (MySQL is told to, Drivers\MySqlDriver), so a step that writes what is there already is no loss.
     *
     * @param Builder<Model> $query
     * @param array<string, mixed> $values
     */
    private function apply(Builder $query, array $values): bool
    {
        if ($query->update($values) !== 1) {
            return false;
        }
        $this->sync($values);

        return true;
    }

    /**
     * The model takes what was written; an expression the database worked out is left for a refresh.
     *
     * @param array<string, mixed> $values
     */
    private function sync(array $values): void
    {
        $plain = array_filter($values, static fn(mixed $value): bool => !$value instanceof Expression);
        $this->row->forceFill($plain)->syncOriginalAttributes(array_keys($plain));
    }
}
