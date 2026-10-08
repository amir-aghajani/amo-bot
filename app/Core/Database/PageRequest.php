<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Support\Input;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What a paged list was asked for, read once from the request's query string: the page (1 and up — Page::fetch()
 * clamps it to the last), the search box's text (Page::term(), applied by search()), the filters, each read as what it
 * may be — a case of the list's enum, a row's id, the shop's days (dates()) — anything else being no filter at all, and
 * the order (sort()).
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
     * A filter that is one case of a string-backed enum (a status tab, a type pill); null — no filter — for anything
     * else.
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    public function enum(string $name, string $enum): ?\BackedEnum
    {
        return $enum::tryFrom(Input::text($this->query, $name));
    }

    /** A filter that names a row — a server, a customer group, a level: its id, null when it names none. */
    public function id(string $name): ?int
    {
        $id = Input::integer($this->query, $name);

        return $id !== null && $id > 0 ? $id : null;
    }

    /** A filter's text as sent, trimmed ("" for none): one that is no enum case, like the subscriptions' «expiring». */
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
     * «#12» in the search box: the row the list numbers 12, and nothing else — how another screen links to one of its
     * rows (searchLink). Null for any other search, a bare number among them.
     */
    public function row(): ?int
    {
        return str_starts_with($this->term, '#') ? $this->number() : null;
    }

    /**
     * The search box on a list's query, one rule for every list: «#12» is the row the list numbers 12 alone (row());
     * any other term is the list's own search — `$search` is handed the query, the term and the term as a bare number
     * (number()), one field among the list's others —; an empty box narrows nothing.
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

    /** The days the list is narrowed to: `from` and `to`, dates of the shop's calendar, both included, either alone. */
    public function dates(): DateRange
    {
        return DateRange::of(Input::text($this->query, 'from'), Input::text($this->query, 'to'));
    }

    /**
     * The order the list was asked for: `sort`, one of the list's keys — `$keys`, each with what it orders by (Sort) —,
     * or the list's own (`$default`) when it names none of them; `dir`, asc or desc — desc, the newest and the largest
     * first, when it says neither.
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
