<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Modules\Telegram\Admin\Door\ApiFile;

/**
 * What a management screen shows: its title, its lines (Telegram's HTML: whatever came from a person goes through
 * Fmt::e()), its buttons in rows — a Persian reader's right first in each row — and, for a screen that is a picture (a
 * receipt), the picture with the lines as its caption. Every screen ends with the way back (back(), home()).
 */
final class View
{
    /** The most of a message Telegram shows; a longer screen is cut, saying so. */
    private const TEXT_MOST = 4000;

    /** The most of a picture's caption. */
    private const CAPTION_MOST = 1000;

    /** The most choices a page of paged() lays: Telegram takes at most 100 buttons, the screen's own rows beside them. */
    public const CHOICES_MOST = 40;

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
     * @param string $title Plain text: escaped here
     */
    public function __construct(private readonly string $title) {}

    /** A screen titled `$title` (plain text), with `$path` — the sections it is in — above it. */
    public static function make(string $title, string ...$path): self
    {
        $view = new self($title);
        if ($path !== []) {
            $view->lines[] = '<i>' . Fmt::e(implode(' › ', $path)) . '</i>';
        }

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
     * Facts, one a line: «label: value» — the value HTML, a null one left out.
     *
     * @param array<string, string|null> $facts
     */
    public function facts(array $facts): self
    {
        foreach ($facts as $label => $value) {
            if ($value !== null) {
                $this->lines[] = Fmt::e($label) . ': ' . $value;
            }
        }

        return $this;
    }

    /** A note in italics under the rest: a hint, what a button does. */
    public function note(string $html): self
    {
        $this->lines[] = '';
        $this->lines[] = '<i>' . $html . '</i>';

        return $this;
    }

    /** A row of buttons, a Persian reader's right first; a null one (a button the screen does not offer now) left out. */
    public function row(?Button ...$buttons): self
    {
        $buttons = self::offered($buttons);
        if ($buttons !== []) {
            $this->rows[] = $buttons;
        }

        return $this;
    }

    /** Buttons laid in rows of `$perRow`; a null one left out. */
    public function grid(int $perRow, ?Button ...$buttons): self
    {
        foreach (array_chunk(self::offered($buttons), max(1, $perRow)) as $row) {
            $this->rows[] = $row;
        }

        return $this;
    }

    /**
     * The row to a list's other pages — «بعدی ›», where it is, «‹ قبلی» —, each button `$screen`'s `$action` with
     * `$args` and the page; nothing for one page.
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
     * The pages' row (pager(), paged()), `$button` making each of its buttons from its label and page.
     *
     * @param \Closure(string, int): Button $button
     */
    private function pages(int $page, int $pages, \Closure $button): self
    {
        if ($pages <= 1) {
            return $this;
        }

        return $this->row(
            $page < $pages ? $button('بعدی ›', $page + 1) : null,
            $button(Fmt::number($page) . ' / ' . Fmt::number($pages), $page),
            $page > 1 ? $button('‹ قبلی', $page - 1) : null,
        );
    }

    /**
     * @param array<int|string, Button|null> $buttons
     * @return list<Button>
     */
    private static function offered(array $buttons): array
    {
        return array_values(array_filter($buttons, static fn(?Button $button): bool => $button !== null));
    }

    /** The way back: «⬅️ بازگشت» to `$screen` (with its action and arguments), and «🏠 مدیریت» beside it. */
    public function back(string $screen, string $action = Nav::SHOW, int|string ...$args): self
    {
        $home = $screen === Screens::HOME && $action === Nav::SHOW;
        $this->rows[] = $home
            ? [Button::to('🏠 مدیریت', Screens::HOME)]
            : [Button::to('⬅️ بازگشت', $screen, $action, ...$args), Button::to('🏠 مدیریت', Screens::HOME)];

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
     * @return array{inline_keyboard: list<list<array<string, string>>>}
     */
    public function keyboard(): array
    {
        return ['inline_keyboard' => array_map(static fn(array $row): array => array_map(static fn(Button $button): array => $button->markup(), $row), $this->rows)];
    }
}
