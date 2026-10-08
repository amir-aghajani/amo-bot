<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The order a paged list is read in: one of the list's keys and its direction, as PageRequest::sort() read them, with
 * what the key orders by — never anything the request names. Page::fetch() puts it on the query, the row's id after it
 * in the same direction, so rows that tie keep one place from page to page and the other direction is the exact
 * reverse; and says it in the list's meta.
 */
final class Sort
{
    /**
     * `$by` is what the key orders by, in turn: a column, or an alias the rows read with them (a count); SQL of the
     * directory's own (a balance with none at 0, a deadline with "never" last); a subquery, none counting as 0.
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
