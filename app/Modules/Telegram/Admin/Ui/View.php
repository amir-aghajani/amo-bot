<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Modules\Telegram\Admin\Door\ApiFile;

/**
 * What a management screen shows: its title, the path of sections it is in, its lines (Telegram's HTML: whatever came
 * from a person goes through Fmt::e()) — a summary card (card()), sections (section()), help folded away (help()) —,
 * its buttons in rows and, for a screen that is a picture (a receipt), the picture with the lines as its caption.
 *
 * Rows are written in reading order: the first button of a row is a Persian reader's first, on the right — keyboard()
 * lays each row out reversed, as Telegram draws a row left to right (the customer keyboards do the same). Every screen
 * but the home ends with the one way around (back(): «⬅️ بازگشت» · «🏠 خانه»; refresh(): «🔄 تازه‌سازی» beside them
 * on a screen whose figures move), drawn last whatever was added after it.
 */
final class View
{
    /** The most of a message Telegram shows; a longer screen is cut, saying so. */
    private const TEXT_MOST = 4000;

    /** The most of a picture's caption. */
    private const CAPTION_MOST = 1000;

    /** The most choices a page of paged() lays: Telegram takes at most 100 buttons, the screen's own rows beside them. */
    public const CHOICES_MOST = 40;

    /** The longest label that shares its row with another in tiles(); a longer one has a row of its own. */
    public const TILE_MOST = 20;

    /**
     * @var list<string>
     */
    private array $lines = [];

    /**
     * @var list<list<Button>>
     */
    private array $rows = [];

    private ?ApiFile $picture = null;

    /**
     * @var list<string> What was just done, said under the title (Admin::notice() after a typed answer).
     */
    private array $notices = [];

    /**
     * @var list<string> The sections it is in (make()).
     */
    private array $path = [];

    /** «⬅️ بازگشت»'s button; null for none — the way back is the home, or there is no way around at all. */
    private ?Button $back = null;

    /** Whether the way around is drawn (back()). */
    private bool $around = false;

    /** «🔄 تازه‌سازی»'s button; null for a screen that does not offer it. */
    private ?Button $refresh = null;

    /** A question or a confirmation: a step on the way, no place of its own (trail()). */
    private bool $transient = false;

    /** The window left as it is (stay()). */
    private bool $stays = false;

    /**
     * @param string $title Plain text: escaped here
     */
    public function __construct(private readonly string $title) {}

    /** A screen titled `$title` (plain text), with `$path` — the sections it is in — under it. */
    public static function make(string $title, string ...$path): self
    {
        $view = new self($title);
        $view->path = array_values($path);
        if ($path !== []) {
            $view->lines[] = '<i>' . Fmt::e(implode(' › ', $path)) . '</i>';
        }

        return $view;
    }

    /**
     * No screen at all: the window stays as it is — a tap answered by a toast alone (Admin::notice() said it), as the
     * pages' middle button is.
     */
    public static function stay(): self
    {
        $view = new self('');
        $view->stays = true;

        return $view;
    }

    /** A line (HTML: escape what a person wrote with Fmt::e()). */
    public function line(string $html = ''): self
    {
        $this->lines[] = $html;

        return $this;
    }

    /** Several lines. */
    public function lines(string ...$html): self
    {
        foreach ($html as $line) {
            $this->lines[] = $line;
        }

        return $this;
    }

    /**
     * The summary card of a row's page or a settings card: its key facts in one quote, a fact a line — «label:
     * <b>value</b>», the value HTML (Fmt's), a null one left out, an empty one «—». A value to copy (<code>, <pre>)
     * is not made bold: Telegram nests nothing with them.
     *
     * @param array<string, string|null> $facts
     */
    public function card(array $facts): self
    {
        $lines = [];
        foreach ($facts as $label => $value) {
            if ($value === null) {
                continue;
            }
            $value = $value === '' ? Fmt::NONE : $value;
            $plain = str_contains($value, '<b>') || str_contains($value, '<code') || str_contains($value, '<pre');
            $lines[] = Fmt::e((string) $label) . ': ' . ($plain ? $value : '<b>' . $value . '</b>');
        }
        if ($lines !== []) {
            // One line of the screen: cut between lines, a quote is never cut open (text()).
            $this->lines[] = '<blockquote>' . implode("\n", $lines) . '</blockquote>';
        }

        return $this;
    }

    /** A section's heading (plain text, with its emoji), a blank line over it: its lines follow. */
    public function section(string $heading): self
    {
        $this->lines[] = '';
        $this->lines[] = '<b>' . Fmt::e($heading) . '</b>';

        return $this;
    }

    /**
     * Help folded away under the rest: what the screen is for, how a thing works (HTML) — closed until tapped. A hint of
     * one short line stays a note().
     */
    public function help(string $html): self
    {
        if (trim($html) === '') {
            return $this;
        }
        $this->lines[] = '';
        $this->lines[] = '<blockquote expandable>❔ ' . $html . '</blockquote>';

        return $this;
    }

    /** A note in italics under the rest: a short hint, what a button does. */
    public function note(string $html): self
    {
        $this->lines[] = '';
        $this->lines[] = '<i>' . $html . '</i>';

        return $this;
    }

    /**
     * A list's line of how many it holds and what narrows it: «۲۳ پرداخت · در انتظار بررسی» — `$filters` plain text
     * (a filter in force, a search).
     */
    public function total(int $count, string $noun, string ...$filters): self
    {
        $said = array_filter($filters, static fn(string $filter): bool => $filter !== '');
        $this->lines[] = implode(' · ', [Fmt::number($count) . ' ' . Fmt::e($noun), ...array_map(Fmt::e(...), $said)]);

        return $this;
    }

    /**
     * A row of buttons in reading order — the first on the right; a null one (a button not offered now) left out, and a
     * copy button of no value or one too long to copy whole (offered()).
     */
    public function row(?Button ...$buttons): self
    {
        $buttons = self::offered($buttons);
        if ($buttons !== []) {
            $this->rows[] = $buttons;
        }

        return $this;
    }

    /** Buttons laid in rows of `$perRow`, in reading order; what row() leaves out left out. */
    public function grid(int $perRow, ?Button ...$buttons): self
    {
        foreach (array_chunk(self::offered($buttons), max(1, $perRow)) as $row) {
            $this->rows[] = $row;
        }

        return $this;
    }

    /**
     * Buttons two a row where both labels are short (TILE_MOST), else one a row — a settings card's edits, a menu whose
     * labels run long —, in reading order; what row() leaves out left out.
     */
    public function tiles(?Button ...$buttons): self
    {
        $buttons = self::offered($buttons);
        for ($i = 0, $n = count($buttons); $i < $n; $i++) {
            $next = $buttons[$i + 1] ?? null;
            if ($next !== null && self::short($buttons[$i]) && self::short($next)) {
                $this->rows[] = [$buttons[$i], $next];
                $i++;

                continue;
            }
            $this->rows[] = [$buttons[$i]];
        }

        return $this;
    }

    /**
     * The row to a list's other pages — «‹ قبلی», where it is, «بعدی ›» —, each button `$screen`'s `$action` with
     * `$args` and the page; nothing for one page. Where it is says «صفحه ۲ از ۵» at a tap (Screens::PAGE).
     */
    public function pager(int $page, int $pages, string $screen, string $action, int|string ...$args): self
    {
        return $this->pages($page, $pages, static fn(string $label, int $at): Button => Button::to($label, $screen, $action, ...[...$args, $at]));
    }

    /**
     * Choices (a plan, a server, an inbound to pick) in rows of `$perRow`, CHOICES_MOST a page: past that, a row to the
     * other pages as ListScreen's — `$to($page)` the screen, action and arguments of page `$page`'s button.
     *
     * @param list<Button> $choices
     * @param \Closure(int): array{0: string, 1: string, 2?: list<int|string>} $to
     */
    public function paged(int $perRow, array $choices, int $page, \Closure $to): self
    {
        [$shown, $page, $pages] = self::slice($choices, $page, self::CHOICES_MOST);
        $this->grid($perRow, ...$shown);

        return $this->pages($page, $pages, static function (string $label, int $at) use ($to): Button {
            $target = $to($at);

            return Button::to($label, $target[0], $target[1], ...($target[2] ?? []));
        });
    }

    /**
     * Page `$page` of a list read whole, `$perPage` rows a page: its rows, the page within bounds, and how many pages
     * there are (pager()'s).
     *
     * @template T
     * @param list<T> $rows
     * @return array{0: list<T>, 1: int, 2: int}
     */
    public static function slice(array $rows, int $page, int $perPage = ListScreen::PER_PAGE): array
    {
        $pages = max(1, (int) ceil(count($rows) / $perPage));
        $page = min(max(1, $page), $pages);

        return [array_slice($rows, ($page - 1) * $perPage, $perPage), $page, $pages];
    }

    /**
     * The pages' row (pager(), paged()), in reading order, `$button` making the previous and next buttons from a label
     * and a page; the middle one says where it is at a tap and leaves the window as it is.
     *
     * @param \Closure(string, int): Button $button
     */
    private function pages(int $page, int $pages, \Closure $button): self
    {
        if ($pages <= 1) {
            return $this;
        }

        return $this->row(
            $page > 1 ? $button(Words::PREVIOUS, $page - 1) : null,
            Button::to(Fmt::number($page) . ' / ' . Fmt::number($pages), Screens::PAGE, Nav::SHOW, $page, $pages),
            $page < $pages ? $button(Words::NEXT, $page + 1) : null,
        );
    }

    /**
     * The buttons offered: a null one left out, and a copy button of nothing or of more than Telegram copies
     * (Button::fits()).
     *
     * @param array<int|string, Button|null> $buttons
     * @return list<Button>
     */
    private static function offered(array $buttons): array
    {
        return array_values(array_filter($buttons, static fn(?Button $button): bool => $button !== null && $button->fits()));
    }

    private static function short(Button $button): bool
    {
        return mb_strlen($button->label) <= self::TILE_MOST;
    }

    /**
     * The way around, under every other row: «⬅️ بازگشت» to `$screen` (with its action and arguments) and «🏠 خانه» —
     * the home alone when the way back is the home.
     */
    public function back(string $screen, string $action = Nav::SHOW, int|string ...$args): self
    {
        $this->around = true;
        $this->back = $screen === Screens::HOME && $action === Nav::SHOW ? null : Button::to(Words::BACK, $screen, $action, ...$args);

        return $this;
    }

    /**
     * «🔄 تازه‌سازی» beside the way around, to `$screen`'s `$action` with its arguments: a screen whose figures move (a
     * list, a queue, the dashboard, the home).
     */
    public function refresh(string $screen, string $action = Nav::SHOW, int|string ...$args): self
    {
        $this->refresh = Button::to(Words::REFRESH, $screen, $action, ...$args);

        return $this;
    }

    /**
     * Words about what was just done, said first, over the title (plain text).
     *
     * @param list<string> $notices
     */
    public function notices(array $notices): self
    {
        $this->notices = [...$this->notices, ...$notices];

        return $this;
    }

    /** The picture the screen is (a receipt), the lines its caption. */
    public function picture(ApiFile $picture): self
    {
        $this->picture = $picture;

        return $this;
    }

    public function photo(): ?ApiFile
    {
        return $this->picture;
    }

    /** It as a step on the way — a question, a confirmation —, not a place a later step names as where it is (trail()). */
    public function transient(): self
    {
        $this->transient = true;

        return $this;
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }

    /** Whether the window is left as it is (stay()). */
    public function stays(): bool
    {
        return $this->stays;
    }

    /**
     * Where it is, as a question or a confirmation asked from it says so: its path and its own title, without the
     * title's emoji.
     *
     * @return list<string>
     */
    public function trail(): array
    {
        $title = Fmt::bare($this->title);

        return $title === '' ? $this->path : [...$this->path, $title];
    }

    /** The message: the title in bold, then the lines — cut to what Telegram shows. */
    public function text(): string
    {
        $notices = array_map(static fn(string $notice): string => '<b>' . Fmt::e($notice) . '</b>', $this->notices);
        $parts = [...$notices, ...($notices === [] ? [] : ['']), '<b>' . Fmt::e($this->title) . '</b>', ...$this->lines];
        $text = rtrim(implode("\n", $parts));
        $most = $this->picture !== null ? self::CAPTION_MOST : self::TEXT_MOST;
        if (mb_strlen($text) <= $most) {
            return $text;
        }

        // Cut between lines — each whole HTML, a quote of many lines too —; one too long by itself is cut inside, the
        // tags it opened closed after.
        $kept = '';
        foreach ($parts as $part) {
            $room = $most - 40 - mb_strlen($kept) - ($kept === '' ? 0 : 1);
            if (mb_strlen($part) <= $room) {
                $kept .= ($kept === '' ? '' : "\n") . $part;

                continue;
            }
            if ($room > 200) {
                $kept .= ($kept === '' ? '' : "\n") . self::cut($part, $room);
            }
            break;
        }

        return rtrim($kept) . "\n…";
    }

    /** `$html` cut to `$room` characters, never inside a tag or an entity, the tags still open closed after «…». */
    private static function cut(string $html, int $room): string
    {
        $piece = mb_substr($html, 0, $room);
        $lt = mb_strrpos($piece, '<');
        if ($lt !== false && mb_strpos($piece, '>', $lt) === false) {
            $piece = mb_substr($piece, 0, $lt);
        }
        $amp = mb_strrpos($piece, '&');
        if ($amp !== false && mb_strpos($piece, ';', $amp) === false) {
            $piece = mb_substr($piece, 0, $amp);
        }
        // A premium emoji cut from its emoji is no emoji: it goes whole.
        $piece = preg_replace('~<tg-emoji\b[^>]*>[^<]*$~u', '', $piece) ?? $piece;

        $open = [];
        preg_match_all('~<(/?)([a-z][a-z0-9-]*)\b[^>]*>~i', $piece, $tags, PREG_SET_ORDER);
        foreach ($tags as $tag) {
            $name = strtolower($tag[2]);
            if ($tag[1] === '') {
                $open[] = $name;

                continue;
            }
            for ($i = count($open) - 1; $i >= 0; $i--) {
                if ($open[$i] === $name) {
                    array_splice($open, $i);

                    break;
                }
            }
        }

        return rtrim($piece) . '…' . implode('', array_map(static fn(string $name): string => "</{$name}>", array_reverse($open)));
    }

    /**
     * The buttons as Telegram takes them: each row reversed — written in reading order, drawn left to right —, the way
     * around last.
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    public function keyboard(): array
    {
        $rows = $this->rows;
        $around = array_values(array_filter([
            $this->back,
            $this->around ? Button::to(Words::HOME, Screens::HOME) : null,
            $this->refresh,
        ]));
        if ($around !== []) {
            $rows[] = $around;
        }

        return ['inline_keyboard' => array_map(static fn(array $row): array => array_map(static fn(Button $button): array => $button->markup(), array_reverse($row)), $rows)];
    }
}
