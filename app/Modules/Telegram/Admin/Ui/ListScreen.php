<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Modules\Telegram\Admin\Door\ApiRefusal;

/**
 * A list of the panel's (payments, orders, customers…) as a management screen: first how many and what narrows them
 * («۲۳ پرداخت · در انتظار بررسی · تاریخ: ۷ روز اخیر», noun(), narrowedBy()), what the list adds up to under that
 * (summary()), PER_PAGE rows a page — each a button to its own screen —, what more the list offers (extra(): «➕
 * افزودن»), its filters as chips (the panel's tabs) and under them a search beside the list's tools (tools()), the pages
 * last, and «🔄 تازه‌سازی» beside the way back. It reads the panel's list route (endpoint(), its rows under key()), whose
 * pages are the panel's 25: a page of this list is cut from them, two when it falls across — or from every row, for a
 * route that answers them all at once (no `meta`). The filter, the search and the page are the screen's state, so
 * coming back to the list finds it as it was left.
 *
 * Actions: `page` (n), `f` (filter, value: `_` for none), `search` (asks), `clear` (the search off).
 */
abstract class ListScreen extends BaseScreen
{
    /** Rows a page of the list. */
    public const PER_PAGE = 8;

    /** The panel's own page size (Core\Database\Page::PER_PAGE). */
    private const API_PAGE = 25;

    /** The filter value that is none. */
    public const ANY = '_';

    /**
     * The update the list is answering (set as show(), act() and input() begin), for a hook that needs the seat or the
     * state: filters(), fixed(), summary(), narrowedBy(), parent().
     */
    protected ?Admin $here = null;

    /** The screen's id, as Screens registers it. */
    abstract protected function screen(): string;

    /** The list's title (plain text, with its emoji). */
    abstract protected function title(): string;

    /**
     * @return list<string> The sections it is in, for the line over it.
     */
    abstract protected function path(): array;

    /** The panel's list route ("/payments"). */
    abstract protected function endpoint(): string;

    /** The key the panel answers the rows under ("payments"). */
    abstract protected function key(): string;

    /**
     * A row as its button: to the row's own screen.
     *
     * @param array<string, mixed> $row
     */
    abstract protected function item(array $row): Button;

    /**
     * The list's filters — the panel's tabs and selects —, each by its query name: its values' labels, the first the
     * one in force at first (ANY: none).
     *
     * @return array<string, array<string, string>>
     */
    protected function filters(): array
    {
        return [];
    }

    /** Whether the list takes a search (the panel's search box). */
    protected function searchable(): bool
    {
        return true;
    }

    /** What the search box searches, for its question ("نام، شناسه تلگرام یا شماره"). */
    protected function searchHint(): string
    {
        return '';
    }

    /** What one row is called, in the line of how many there are («۲۳ پرداخت»). */
    protected function noun(): string
    {
        return 'مورد';
    }

    /** The words of an empty list. */
    protected function empty(): string
    {
        return 'موردی پیدا نشد.';
    }

    /**
     * Lines under the line of how many, before the rows: what the panel's list shows beside them (a figure from its
     * meta: «💰 ۶ پرداخت موفق · جمع …»). Read before filters() and narrowedBy(), which may say what it found.
     *
     * @param array<string, mixed> $meta
     * @return list<string>
     */
    protected function summary(array $meta): array
    {
        return [];
    }

    /**
     * What else narrows the list beyond its chips and its search — the panel's selects, a range of days —, each plain
     * text, said after them in the line of how many: «۲ سفارش · نوع: خرید · تاریخ: ۷ روز اخیر».
     *
     * @return list<string>
     */
    protected function narrowedBy(): array
    {
        return [];
    }

    /**
     * Buttons beside «🔎 جستجو», after it in reading order, under the filter chips: what else narrows or orders the list
     * («🎛 فیلتر و ترتیب», «✖️ حذف فیلترها»); a null one (not offered now) left out. Kept to one or two: a row.
     *
     * @return list<Button|null>
     */
    protected function tools(Admin $admin): array
    {
        return [];
    }

    /** Buttons beside the rows: «➕ افزودن», a bulk action. */
    protected function extra(View $view, Admin $admin): void {}

    /**
     * Whether the page's rows are kept in the screen's state (`rows`, by id) for a row's own screen to read, when the panel
     * has no route for one row (a review).
     */
    protected function keepsRows(): bool
    {
        return false;
    }

    /**
     * Row `$id` of the page list `$list` kept (keepsRows()), for the row's own screen; null when it is not there (the
     * list moved on).
     *
     * @return array<string, mixed>|null
     */
    public static function keptRow(Admin $admin, string $list, int $id): ?array
    {
        $rows = (array) ($admin->state($list)['rows'] ?? []);
        $row = $rows[$id] ?? null;

        return is_array($row) ? $row : null;
    }

    /** Where «⬅️ بازگشت» goes: the menu the list is in. */
    protected function parent(): string
    {
        return Screens::HOME;
    }

    /**
     * The query beside the page and the search (a route's own fixed parameter).
     *
     * @return array<string, scalar>
     */
    protected function fixed(): array
    {
        return [];
    }

    public function show(Admin $admin, array $args): View
    {
        $this->here = $admin;
        $state = $admin->state($this->screen());
        $page = min(max(1, (int) ($state['page'] ?? 1)), self::PAGE_MOST);
        [$rows, $meta] = $this->fetch($admin, $state, $page);
        $total = (int) ($meta['total'] ?? count($rows));
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            $admin->remember($this->screen(), ['page' => $pages]);

            return $this->show($admin, $args);
        }

        if ($this->keepsRows()) {
            $admin->remember($this->screen(), ['rows' => array_column($rows, null, 'id')]);
        }

        // What the list adds up to is read first (a hook may keep what it found for filters(): a tab's count), and said
        // under the line of how many.
        $figures = $this->summary($meta);
        $said = [];
        foreach ($this->filters() as $name => $values) {
            $value = $this->filterValue($state, $name);
            if ($value !== self::ANY && isset($values[$value])) {
                $said[] = $values[$value];
            }
        }
        $search = (string) ($state['search'] ?? '');
        if ($search !== '') {
            $said[] = '🔎 «' . $search . '»';
        }
        // How many and what narrows them first; which page is the pages' middle button's to say.
        $view = View::make($this->title(), ...$this->path())->total($total, $this->noun(), ...$said, ...$this->narrowedBy());
        foreach ($figures as $line) {
            $view->line($line);
        }
        if ($rows === []) {
            $view->line()->line($this->empty());
        }

        foreach ($rows as $row) {
            $view->row($this->item($row));
        }
        $this->extra($view, $admin);
        foreach ($this->filters() as $name => $values) {
            $current = $this->filterValue($state, $name);
            $chips = [];
            foreach ($values as $value => $label) {
                $chips[] = Button::to(($current === (string) $value ? Words::CHIP : '') . $label, $this->screen(), 'f', $name, (string) $value);
            }
            $view->grid(count($chips) > 4 ? 3 : max(1, count($chips)), ...$chips);
        }
        $view->row(
            match (true) {
                !$this->searchable() => null,
                $search === '' => Button::to(Words::SEARCH, $this->screen(), 'search'),
                default => Button::to(Words::CLEAR_SEARCH, $this->screen(), 'clear'),
            },
            ...$this->tools($admin),
        );
        $view->pager($page, $pages, $this->screen(), 'page');

        return $view->back($this->parent())->refresh($this->screen());
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $this->here = $admin;
        switch ($action) {
            case 'page':
                $admin->remember($this->screen(), ['page' => self::page($args)]);
                break;
            case 'f':
                // Only a value its chips offer: an older window's chip of a value since gone narrows nothing.
                if (isset($args[0], $args[1]) && isset($this->filters()[$args[0]][$args[1]])) {
                    $filters = (array) ($admin->state($this->screen())['f'] ?? []);
                    $filters[$args[0]] = $args[1];
                    $admin->remember($this->screen(), ['f' => $filters, 'page' => 1]);
                }
                break;
            case 'search':
                return $admin->ask(new Prompt('چه چیزی جستجو شود؟', $this->screen(), 'search', hint: Fmt::e($this->searchHint())));
            case 'clear':
                $admin->remember($this->screen(), ['search' => '', 'page' => 1]);
                break;
            default:
                return $this->action($admin, $action, $args);
        }

        return $this->show($admin, []);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $this->here = $admin;
        if ($action === 'search') {
            $admin->remember($this->screen(), ['search' => mb_substr($answer->text(), 0, 100), 'page' => 1]);

            return $this->show($admin, []);
        }

        return $this->typed($admin, $action, $args, $answer);
    }

    /**
     * An action of the list's own (a bulk action): the list again by default.
     *
     * @param list<string> $args
     * @throws ApiRefusal
     */
    protected function action(Admin $admin, string $action, array $args): View
    {
        return $this->show($admin, []);
    }

    /**
     * An answer to a question of the list's own.
     *
     * @param list<string> $args
     * @throws ApiRefusal
     */
    protected function typed(Admin $admin, string $action, array $args, Answer $answer): View
    {
        return $this->show($admin, []);
    }

    /**
     * The rows of this list's page `$page`, and the panel's meta.
     *
     * @param array<string, mixed> $state
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     * @throws ApiRefusal
     */
    private function fetch(Admin $admin, array $state, int $page): array
    {
        $query = $this->fixed();
        foreach (array_keys($this->filters()) as $name) {
            $value = $this->filterValue($state, $name);
            if ($value !== self::ANY) {
                $query[$name] = $value;
            }
        }
        $search = (string) ($state['search'] ?? '');
        if ($search !== '') {
            $query['search'] = $search;
        }

        $start = ($page - 1) * self::PER_PAGE;
        $apiPage = intdiv($start, self::API_PAGE) + 1;
        $answer = $admin->api->get($this->endpoint(), $query + ['page' => $apiPage]);
        $rows = Json::rows($answer, $this->key());
        if (!is_array($answer['meta'] ?? null)) {
            // A route that answers every row at once (no pages of its own): this list's page is cut from all of them.
            return [array_slice($rows, $start, self::PER_PAGE), ['total' => count($rows), 'last_page' => 1]];
        }
        $meta = $answer['meta'];
        $offset = $start % self::API_PAGE;
        if ($offset + self::PER_PAGE > self::API_PAGE && $apiPage < (int) ($meta['last_page'] ?? 1)) {
            $next = $admin->api->get($this->endpoint(), $query + ['page' => $apiPage + 1]);
            $rows = [...$rows, ...Json::rows($next, $this->key())];
        }

        return [array_slice($rows, $offset, self::PER_PAGE), $meta];
    }

    /**
     * The value in force of filter `$name` in the screen's `$state`: the one picked, else its first (ANY: none) — read
     * from filters(), so never from inside it.
     *
     * @param array<string, mixed> $state
     */
    protected function filterValue(array $state, string $name): string
    {
        $set = (array) ($state['f'] ?? []);
        if (isset($set[$name])) {
            return (string) $set[$name];
        }
        $values = $this->filters()[$name] ?? [];

        return (string) (array_key_first($values) ?? self::ANY);
    }
}
