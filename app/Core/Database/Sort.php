<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The order a paged list is read in: a key, its direction, and what the key orders by. The row's id follows in the same
 * direction, so ties keep their place from page to page and the other direction is the exact reverse.
 */
final class Sort
{
    /**
     * `$by`, in turn: a column or an alias the rows select (a count); raw SQL (a deadline with "never" last); or a
     * subquery, where none counts as 0.
     *
     * @param list<string|Expression|QueryBuilder> $by
     */
    public function __construct(
        public readonly string $key,
        public readonly SortDirection $dir,
        private readonly array $by,
    ) {}

    /**
     * @template TModel of Model
     * @param Builder<TModel> $query
     */
    public function apply(Builder $query): void
    {
        $dir = $this->dir->value;
        foreach ($this->by as $term) {
            if ($term instanceof QueryBuilder) {
                $query->orderByRaw("COALESCE(({$term->toSql()}), 0) {$dir}", $term->getBindings());
            } else {
                $query->orderBy($term, $dir);
            }
        }

        $model = $query->getModel();
        if ($this->by !== [$model->getKeyName()] && $this->by !== [$model->getQualifiedKeyName()]) {
            $query->orderBy($model->getQualifiedKeyName(), $dir);
        }
    }

    /** @return array{sort: string, dir: string} What the list's meta says of its order. */
    public function meta(): array
    {
        return ['sort' => $this->key, 'dir' => $this->dir->value];
    }
}
