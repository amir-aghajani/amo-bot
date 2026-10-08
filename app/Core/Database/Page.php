<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One page of an admin list — what every list answers: the rows of the requested page (clamped to what exists) and
 * the meta the screen's pager needs, the order a sorted list is in, and the list's own figures (a tab's count, the
 * money it adds up to — with(), tally()).
 * A directory returns one (Page::fetch() from a PageRequest), its controller names the rows (toArray('orders')). The
 * order is the Sort the request asked for, or — a list that is not sorted, a ledger — the caller's own. The search
 * helpers below are what every directory matches its term with.
 */
final class Page
{
    /** Rows per page of every admin list. */
    public const PER_PAGE = 25;

    /** Longer search terms are cut: nothing anyone looks for is longer, and LIKE patterns stay cheap. */
    private const TERM_MAX = 64;

    /** What a search term's own `%` and `_` (and this character itself) are escaped with in a LIKE pattern. */
    private const LIKE_ESCAPE = '!';

    /**
     * Up to this many rows a search finds are handed to a list as their ids (matches()); a term that finds more stays a
     * subquery. Low, so finding out costs little when the term is broad: measured on 40,000 customers, «ali» pays a few
     * milliseconds to learn it, a customer's or a service's own name saves a pass over every order.
     */
    private const MATCHES_LISTED = 200;

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, int|string> $figures
     */
    private function __construct(
        public readonly array $rows,
        public readonly int $page,
        public readonly int $total,
        private readonly ?Sort $sort = null,
        private readonly array $figures = [],
    ) {}

    /**
     * The page the request asks for of `$query` (a page past the end is the last one), in the order `$sort` says, each
     * row as `$present` makes it.
     *
     * @template TModel of Model
     * @param Builder<TModel> $query Filtered — and ordered, for a list without a Sort
     * @param \Closure(TModel): array<string, mixed> $present One row → what the screen gets
     */
    public static function fetch(Builder $query, PageRequest $request, \Closure $present, ?Sort $sort = null): self
    {
        return self::fetchTogether($query, $request, static fn(Collection $rows): array => array_values($rows->map($present)->all()), $sort);
    }

    /**
     * fetch(), the page's rows presented together: `$present` is handed them, in the list's order, and answers what the
     * screen gets of each, in that order — so what judging a row asks of its shop (whether its wallet is on, what an
     * agent's traffic covers) is asked once for the page, not once a row.
     *
     * @template TModel of Model
     * @param Builder<TModel> $query Filtered — and ordered, for a list without a Sort
     * @param \Closure(Collection<int, TModel>): list<array<string, mixed>> $present The page's rows → what the screen gets
     */
    public static function fetchTogether(Builder $query, PageRequest $request, \Closure $present, ?Sort $sort = null): self
    {
        $sort?->apply($query);
        $total = (clone $query)->count();
        $page = min($request->page, max(1, (int) ceil($total / self::PER_PAGE)));

        return new self($present($query->forPage($page, self::PER_PAGE)->get()), $page, $total, $sort);
    }

    /** A list with nothing in it (a ledger that does not exist yet). */
    public static function empty(): self
    {
        return new self([], 1, 0);
    }

    /**
     * The same page with the list's own figures beside the pager's — the count a tab shows, the money the list adds up to.
     *
     * @param array<string, int|string> $figures
     */
    public function with(array $figures): self
    {
        return new self($this->rows, $this->page, $this->total, $this->sort, array_replace($this->figures, $figures));
    }

    /**
     * How many rows `$query` holds and the money they add up to (`$column`, an amount): what a list says of its takings —
     * the orders it sold, the payments that came in — beside its pager.
     *
     * @template TModel of Model
     * @param Builder<TModel> $query
     * @return array{int, numeric-string}
     */
    public static function tally(Builder $query, string $column): array
    {
        $amount = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column));
        $row = $query->toBase()->selectRaw("COUNT(*) AS tally_rows, COALESCE(SUM({$amount}), 0) AS tally_amount")->first();

        return [(int) ($row->tally_rows ?? 0), Money::normalize(is_numeric($row->tally_amount ?? null) ? (string) $row->tally_amount : '0')];
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / self::PER_PAGE));
    }

    /** @return array<string, int|string> {page, per_page, total, last_page}, a sorted list's {sort, dir}, and the list's figures */
    public function meta(): array
    {
        return ['page' => $this->page, 'per_page' => self::PER_PAGE, 'total' => $this->total, 'last_page' => $this->lastPage()] + ($this->sort?->meta() ?? []) + $this->figures;
    }

    /**
     * What the screen gets: the rows under the list's name, and the meta.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $key): array
    {
        return [$key => $this->rows, 'meta' => $this->meta()];
    }

    /**
     * The search box's text as the directories match it: trimmed, Persian digits made Latin, cut to
     * TERM_MAX; "" when there is none.
     *
     * @param array<string, mixed> $query The request's query string
     */
    public static function term(array $query): string
    {
        return mb_substr(Persian::latinDigits(Input::text($query, 'search')), 0, self::TERM_MAX);
    }

    /**
     * Rows whose `$column` holds `$term` anywhere, the term's own `%` and `_` taken literally. The pattern
     * names its escape character: a backslash escapes nothing in SQLite, nor in MySQL under
     * NO_BACKSLASH_ESCAPES (common on shared hosting) — and client names are full of underscores ("amir_2").
     *
     * @template TModel of Model
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    public static function whereContains(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        $escaped = strtr($term, [self::LIKE_ESCAPE => self::LIKE_ESCAPE . self::LIKE_ESCAPE, '%' => self::LIKE_ESCAPE . '%', '_' => self::LIKE_ESCAPE . '_']);
        $column = $query->getQuery()->getGrammar()->wrap($column);

        return $query->whereRaw("{$column} LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", ['%' . $escaped . '%'], $boolean);
    }

    /**
     * whereContains(), joined with OR.
     *
     * @template TModel of Model
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    public static function orWhereContains(Builder $query, string $column, string $term): Builder
    {
        return self::whereContains($query, $column, $term, 'or');
    }

    /**
     * What a search finds of another table (`$keys`, selecting its key: the customers a term names, the plans, the
     * services), for a list to narrow itself by with whereIn(): the keys themselves when they are few — a short list the
     * index on the list's own column takes, so the list reads only the rows they name, and the search runs once for the
     * page, its count and its figures — or, for a term that finds more than MATCHES_LISTED, the subquery itself (a term
     * that broad has the list read most of its rows anyway). A LIKE '%…%' reads every row of its table in the shop, so a
     * search costs one pass over the shop's rows of the table searched, never one per row of the list.
     *
     * @template TModel of Model
     * @param Builder<TModel> $keys
     * @return list<int|string>|Builder<TModel>
     */
    public static function matches(Builder $keys): array|Builder
    {
        $found = (clone $keys)->limit(self::MATCHES_LISTED + 1)->pluck($keys->getModel()->getKeyName())->all();

        return count($found) > self::MATCHES_LISTED ? $keys : array_values($found);
    }
}
