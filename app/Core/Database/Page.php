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
 * One page of a paged list: its rows (the requested page, clamped to the last), the pager's meta, the Sort it is in
 * and the list's own figures (a tab's count, a total — with(), tally()). A directory builds it with fetch(); its
 * controller answers toArray('orders'). Also the search helpers every directory matches its term with.
 */
final class Page
{
    /** Rows per page of every admin list. */
    public const PER_PAGE = 25;

    /** Longer search terms are cut: nothing anyone looks for is longer, and LIKE patterns stay cheap. */
    private const TERM_MAX = 64;

    /** The marks that set a text's direction and draw nothing: U+061C, U+200E/U+200F, the embeddings and the isolates. */
    private const DIRECTION_MARKS = '/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** What a search term's own `%` and `_` (and this character itself) are escaped with in a LIKE pattern. */
    private const LIKE_ESCAPE = '!';

    /**
     * Up to this many rows a search finds are handed to a list as ids (matches()); a term that finds more stays a
     * subquery. Kept low so a broad term costs little to find out (measured on 40,000 customers).
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
     * The requested page of `$query` (a page past the end is the last one), in `$sort`'s order, each row presented.
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
     * fetch(), with the page's rows presented in one call, so what judging a row asks of the shop (is the wallet on,
     * what does an agent's traffic cover) is asked once per page, not once per row.
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
     * The same page with the list's own figures added to its meta (a tab's count, the money the list adds up to).
     *
     * @param array<string, int|string> $figures
     */
    public function with(array $figures): self
    {
        return new self($this->rows, $this->page, $this->total, $this->sort, array_replace($this->figures, $figures));
    }

    /**
     * How many rows `$query` holds and the sum of their `$column` (an amount) — a list's takings, in one query.
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
     * The answer: the rows under `$key`, and the meta.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $key): array
    {
        return [$key => $this->rows, 'meta' => $this->meta()];
    }

    /**
     * The search box's text: without its invisible direction marks (a «#12» copied from a bot message carries one before
     * it), trimmed, Persian digits made Latin, cut to TERM_MAX; "" when there is none.
     *
     * @param array<string, mixed> $query The request's query string
     */
    public static function term(array $query): string
    {
        $typed = (string) preg_replace(self::DIRECTION_MARKS, '', Input::text($query, 'search'));

        return mb_substr(Persian::latinDigits(trim($typed)), 0, self::TERM_MAX);
    }

    /**
     * Rows whose `$column` contains `$term`, its `%` and `_` taken literally (client names are full of underscores). The
     * pattern names its escape character: a backslash escapes nothing in SQLite, nor in MySQL under NO_BACKSLASH_ESCAPES.
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
     * What a search of another table finds (`$keys`: e.g. the customers matching a term), for a list to narrow itself
     * with whereIn(): the keys themselves when there are at most MATCHES_LISTED — the list's index then reads only those
     * rows, and the LIKE scan runs once for the page, its count and its figures — else the subquery.
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
