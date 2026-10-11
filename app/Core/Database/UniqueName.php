<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Exceptions\ValidationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Saving a row whose `name` is unique among its kind (a customer group, an agency level). taken() lets a form report
 * the clash with its other errors; save() turns the unique index's error — another save took the name in the same
 * moment — into the same validation error.
 */
final class UniqueName
{
    /** Whether another row of the model's kind (within its global scopes: the current shop) has the name. */
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
