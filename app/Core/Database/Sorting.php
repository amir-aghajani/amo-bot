<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Exceptions\ValidationException;
use App\Support\Persian;
use Illuminate\Database\Eloquent\Model;

/** The admin's own order for rows that carry a `sort` column (plans, categories, payment methods). */
final class Sorting
{
    /** The most ids one reorder takes: every hand-ordered list is far shorter, and each id is a write. */
    public const MAX_IDS = 1000;

    /**
     * Renumber the rows: the given ids first, in that order, then the rest in their current order. Duplicates and
     * unknown ids are ignored; one transaction.
     *
     * @param class-string<Model> $model
     * @param list<int> $ids
     * @throws ValidationException 422 on `ids`: more than MAX_IDS — before anything is read
     */
    public static function reorder(string $model, array $ids): void
    {
        self::refuseTooMany($ids);

        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        $rest = $model::query()->whereNotIn('id', $ids)->oldest('sort')->oldest('id')->pluck('id')->all();

        $model::query()->getConnection()->transaction(static function () use ($model, $ids, $rest): void {
            foreach (array_merge($ids, $rest) as $position => $id) {
                $model::query()->whereKey($id)->update(['sort' => $position + 1]);
            }
        });
    }

    /**
     * Refuse a list of more ids than one reorder takes (MAX_IDS), before any of them is read.
     *
     * @param array<mixed> $ids
     * @throws ValidationException 422 on `ids`
     */
    public static function refuseTooMany(array $ids): void
    {
        if (count($ids) > self::MAX_IDS) {
            throw ValidationException::on('ids', 'لیست شناسه‌ها بلندتر از حد است؛ حداکثر ' . Persian::number(self::MAX_IDS) . ' شناسه.');
        }
    }

    /**
     * The position a new row takes: after every existing one.
     *
     * @param class-string<Model> $model
     */
    public static function next(string $model): int
    {
        return (int) $model::query()->max('sort') + 1;
    }
}
