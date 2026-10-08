<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Compare-and-swap for state columns (orders.status, payments.status): the move is one conditional
 * `UPDATE … WHERE id = ? AND status IN (from)`, so of two processes racing for the same transition —
 * an admin approving while the auto-approve timer fires, two tabs, two taps in webhook mode — exactly
 * one wins and the other learns it lost instead of acting twice. A row worked through over time by one
 * process at a time is a Lease's.
 */
final class Transitions
{
    /**
     * Move `$column` from one of `$from` to `$to`, writing `$attributes` in the same statement. True
     * when this call moved the row; false when another process got there first — the model is then
     * re-read so the caller sees the state that won. `$to` is never one of `$from`: a move that stays
     * where it is moves nothing.
     *
     * @param list<\BackedEnum> $from
     * @param array<string, mixed> $attributes
     */
    public static function move(Model $model, string $column, array $from, \BackedEnum $to, array $attributes = []): bool
    {
        $query = $model->newModelQuery()->whereIn($column, array_map(static fn(\BackedEnum $state): int|string => $state->value, $from));

        return self::write($model, $query, [$column => $to] + $attributes);
    }

    /**
     * Take over a claim whose owner died: the row is already in `$state` and nobody has touched it for
     * `$idleSeconds` (its updated_at is the fence — the winner's fresh timestamp shuts everyone else
     * out). True when this call took it.
     *
     * @param array<string, mixed> $attributes
     */
    public static function reclaim(Model $model, string $column, \BackedEnum $state, int $idleSeconds, array $attributes = []): bool
    {
        $query = $model->newModelQuery()
            ->where($column, $state->value)
            ->where($model->getUpdatedAtColumn(), '<=', $model->freshTimestamp()->subSeconds($idleSeconds));

        return self::write($model, $query, [$column => $state] + $attributes);
    }

    /**
     * Mark the row for something done once — `$column`, a moment, set to now while it is empty, or older than
     * `$before` (a window that has passed) — so of two processes about to tell a customer the same thing, one does.
     * True when this call set it; the model then holds the moment.
     */
    public static function claim(Model $model, string $column, ?\DateTimeInterface $before = null): bool
    {
        $now = $model->freshTimestamp();
        $claimed = $model->newModelQuery()
            ->whereKey($model->getKey())
            ->where(static function (Builder $query) use ($column, $before): void {
                $query->whereNull($column);
                if ($before !== null) {
                    $query->orWhere($column, '<', $before);
                }
            })
            ->update([$column => $now]) === 1;

        if ($claimed) {
            $model->setAttribute($column, $now)->syncOriginalAttribute($column);
        }

        return $claimed;
    }

    /**
     * @param Builder<Model> $query The row's conditions, besides its key
     * @param array<string, mixed> $attributes
     */
    private static function write(Model $model, Builder $query, array $attributes): bool
    {
        // Filling first lets the casts turn enums, dates and JSON into their storage form.
        $model->forceFill($attributes);
        $model->updateTimestamps();

        // Nothing to write (a reclaim inside the second of the claim) cannot have moved the row either.
        $changes = $model->getDirty();
        $written = $changes !== [] && $query->whereKey($model->getKey())->toBase()->update($changes) === 1;

        if ($written) {
            $model->syncOriginal();
        } else {
            $model->refresh();
        }

        return $written;
    }
}
