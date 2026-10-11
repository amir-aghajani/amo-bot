<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Support\Input;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What a paged list was asked for, read once from the query string: the page, the search term, the filters — each read
 * as what it may be (an enum case, a row id, a range of days); anything else is no filter — and the order.
 */
final class PageRequest
{
    /** @param array<string, mixed> $query */
    private function __construct(
        public readonly int $page,
        public readonly string $term,
        private readonly array $query,
    ) {}

    /** @param array<string, mixed> $query The request's query string */
    public static function fromQuery(array $query): self
    {
        return new self(max(1, Input::integer($query, 'page') ?? 1), Page::term($query), $query);
    }

    /**
     * A filter that is one case of a string-backed enum (a status tab); null — no filter — for anything else.
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    public function enum(string $name, string $enum): ?\BackedEnum
    {
        return $enum::tryFrom(Input::text($this->query, $name));
    }

    /** A filter that names a row (a server, a customer): its id, null when it names none. */
    public function id(string $name): ?int
    {
        $id = Input::integer($this->query, $name);

        return $id !== null && $id > 0 ? $id : null;
    }

    /** A filter's text as sent, trimmed ("" for none) — for a value that is no enum case, like the subscriptions' `expiring`. */
    public function text(string $name): string
    {
        return Input::text($this->query, $name);
    }

    /** The search box as a row's number — "12" or "#12" — or null when it is not one. */
    public function number(): ?int
    {
        return Input::integerOf(ltrim($this->term, '#'));
    }

    /**
     * `#12` in the search box: the row numbered 12 and nothing else — how one screen links to another's row. Null for
     * any other search, a bare number included.
     */
    public function row(): ?int
    {
        return str_starts_with($this->term, '#') ? $this->number() : null;
    }

    /**
     * Apply the search box to a list's query: `#12` is row 12 alone; any other term goes to `$search` with the term and
     * the term as a number (a bare 12 may match the row's number among its other fields); an empty box narrows nothing.
     *
     * @template TModel of Model
     * @param Builder<TModel> $query
     * @param \Closure(Builder<TModel>, string, int|null): void $search
     * @param string|null $numbered What the list numbers its rows by when it is not their key (a commission is numbered by its payment)
     */
    public function search(Builder $query, \Closure $search, ?string $numbered = null): void
    {
        $row = $this->row();
        if ($row !== null && $numbered !== null) {
            $query->where($numbered, $row);
        } elseif ($row !== null) {
            $query->whereKey($row);
        } elseif ($this->term !== '') {
            $search($query, $this->term, $this->number());
        }
    }

    /** The days the list is narrowed to: `from` and `to`, days of the shop's calendar, both included, either optional. */
    public function dates(): DateRange
    {
        return DateRange::of(Input::text($this->query, 'from'), Input::text($this->query, 'to'));
    }

    /**
     * The order asked for: `sort` names one of `$keys` (each mapped to what it orders by), else `$default`; `dir` is asc
     * or desc, desc by default. Never orders by anything the request names directly.
     *
     * @param array<string, string|Expression|QueryBuilder|list<string|Expression|QueryBuilder>> $keys
     */
    public function sort(array $keys, string $default): Sort
    {
        $key = Input::text($this->query, 'sort');
        if (!array_key_exists($key, $keys)) {
            $key = $default;
        }
        $by = $keys[$key] ?? throw new \LogicException("The list has no sort key «{$default}».");

        return new Sort($key, SortDirection::tryFrom(Input::text($this->query, 'dir')) ?? SortDirection::Desc, is_array($by) ? $by : [$by]);
    }
}
