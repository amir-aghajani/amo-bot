<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Exceptions\ValidationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A row whose `name` is unique among its kind — a customer group, an agency level — saved under that name. The table's
 * unique index decides: taken() lets a form say so beside its other mistakes before anything is written, and save()
 * refuses, the same way, a name another save took in the same moment instead of failing with the index's error.
 */
final class UniqueName
{
    /** Whether another row of the model's kind — in its scope: the current shop's, for a shop's rows — has the name. */
    public static function taken(Model $row, string $name): bool
    {
        return $row->newQuery()
            ->where('name', $name)
            ->when($row->exists, static fn(Builder $query) => $query->whereKeyNot($row->getKey()))
            ->exists();
    }

    /**
     * Save the row as it was filled.
     *
     * @throws ValidationException under `name`, with `$message`, when the name was taken meanwhile
     */
    public static function save(Model $row, string $message): void
    {
        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::on('name', $message);
        }
    }
}
