<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui\Settings;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Support\Unit;

/**
 * A settings card of the panel's as a management screen. Every field's value is said once, in the card's summary
 * (View::card(): a switch as its chip, an empty one «—», the ones its conditions hide left out); its buttons say only
 * what they do — a switch the state a tap sets («▶️ روشن کردن «…»» / «⏸ خاموش کردن «…»»), any other field «✏️ {label}» —,
 * two a row where the labels are short. Over the summary, the card's warnings; under it, where the panel's hint follows
 * what is set, that line (explain(): a short one in italics, a long one with the card's intro folded away in its help);
 * and «❔ راهنما» with every field's hint and the card's notes (the panel's info box). Read from and saved to the panel's
 * API (SettingsSource) a field at a time, as the panel saves its card: a switch is set at a tap — its button carries the
 * value it sets, and a switch that holds customers back (the bot off) asks first (FieldDef::asks()) —, a choice is picked
 * among buttons (the one in force marked ✅), the rest is typed: its question says the value now, an example and the
 * limits, and offers «🗑 خالی کردن» (FieldDef::nullable()) and «↩️ پیش‌فرض» (FieldDef::byDefault()). The panel's own
 * check answers each change: a value it refuses is said, and nothing is kept. Opened from a menu other than its parent
 * (Origin), its «⬅️ بازگشت» goes back there.
 *
 * A long card is split by the panel's parts (parts(): «حجم اضافه», «پورسانت»…): the card shows the fields of no part and
 * a button to each part; a part is a screen of its own — its fields' summary and buttons, its way back the card —, and
 * whatever is done to one of its fields (a switch, a typed value, a question left) comes back to it. A field is opened by
 * its API name from anywhere (FIELD: a search's result, a warning's way to fix it): a typed field asks, a choice offers
 * its choices, a switch shows the card — or its part — it is on, never flipped by a link; one another field hides now
 * shows the card, saying which field shows it.
 *
 * Actions: `e` (i: edit field i; a switch's i, value — 1 on, 0 off — and `y` once asked), `c` (i, n: field i's choice
 * n), `m` (i, n, 1|0: field i's choice n ticked or not among several), `mp` (i, page: those choices' page), `x` (i:
 * field i emptied), `d` (i: field i put back as it comes), `f` (name: the field of that API name opened), `p` (n: part n
 * opened; none: back to the card), `v` (the card again where it was left — its part and origin kept: a question's
 * «✖️ انصراف», a choice's way back), `help`.
 */
abstract class SettingsScreen extends BaseScreen
{
    /** The card again where it was left, its origin kept: the way back of what it opens itself. */
    public const VIEW = 'v';

    /** Every field's hint and the card's notes. */
    public const HELP = 'help';

    /** A field opened by its API name: `Button::to(Words::edit('…'), 'bsw', SettingsScreen::FIELD, 'topup_min')`. */
    public const FIELD = 'f';

    /** A part of the card opened (parts()), by its place; without one, the card again. */
    public const PART = 'p';

    /** The field actions; any other is the card's own (action()). */
    private const FIELD_ACTIONS = ['e', 'c', 'm', 'mp', 'x', 'd'];

    /** Where the part open is kept, in the screen's own state. */
    private const OPEN_PART = '_part';

    /** A switch's third argument once its question was answered yes. */
    private const ASKED = 'y';

    /** The longest explanation said under the summary (explain()); a longer one is folded away with the card's help. */
    private const SAID_MOST = 100;

    /** Said on a field's picker while there is nothing to pick among (no server, no plan in the shop yet). */
    protected const NOTHING_TO_PICK = 'موردی برای انتخاب نیست.';

    /**
     * The update the card is answering (set as show(), act() and input() begin), for a hook that needs the seat or the
     * state: fields(), intro(), source(), parent().
     */
    protected ?Admin $here = null;

    /** The screen's id, as Screens registers it. */
    abstract protected function screen(): string;

    /** The card's title (plain text, with its emoji). */
    abstract protected function title(): string;

    /**
     * @return list<string> The sections it is in.
     */
    abstract protected function path(): array;

    /**
     * @return list<FieldDef> Its fields, in the panel's order.
     */
    abstract protected function fields(): array;

    abstract protected function source(): SettingsSource;

    /** What the card is for, over its fields (HTML); none by default. */
    protected function intro(): string
    {
        return '';
    }

    /**
     * The panel's parts of a long card (its FormParts), each a screen of its own: its label (plain text, with its emoji)
     * and its fields' API names. A field of no part stays on the card. None by default: the card whole.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    protected function parts(): array
    {
        return [];
    }

    /**
     * Whether its switches are chips — «🟢 خریدها» / «⏸ خریدها», a tap the other state, two a row — and so left out of
     * the summary (the chip is the switch and its state, said once): a card of many switches alike (the report's
     * topics). A card of a few says each in its summary, with a button saying the state a tap sets.
     */
    protected function chips(): bool
    {
        return false;
    }

    /** Where «⬅️ بازگشت» goes, opened from its own place (no origin). */
    protected function parent(): string
    {
        return Screens::HOME;
    }

    /** The action «⬅️ بازگشت» opens parent() with (its show(), or a way back that keeps the parent's own origin). */
    protected function parentAction(): string
    {
        return Nav::SHOW;
    }

    /**
     * Lines or buttons beside the fields (a test, a link the card has besides).
     *
     * @param array<string, mixed> $values
     */
    protected function extra(View $view, Admin $admin, array $values): void {}

    /**
     * The panel's warnings as the values stand (plain text), over the fields.
     *
     * @param array<string, mixed> $values
     * @return list<string>
     * @throws ApiRefusal
     */
    protected function warnings(Admin $admin, array $values): array
    {
        return [];
    }

    /**
     * The card's notes — the panel's info box —, shown with the hints (plain text, a line each).
     *
     * @return list<string>
     */
    protected function notes(): array
    {
        return [];
    }

    /**
     * What a field's value means as it stands, under its line (plain text): the panel's hint that follows what is
     * typed; null for none.
     *
     * @param array<string, mixed> $values
     */
    protected function explain(FieldDef $field, array $values): ?string
    {
        return null;
    }

    /** A field's value as the card shows it (HTML), where FieldDef's way does not fit; null for FieldDef's. */
    protected function shows(FieldDef $field, mixed $value): ?string
    {
        return null;
    }

    public function show(Admin $admin, array $args): View
    {
        $this->here = $admin;
        Origin::opened($admin, $this->screen(), $args);
        // Opened afresh (a menu, a search): the card itself, no part left open from before.
        $admin->remember($this->screen(), [self::OPEN_PART => null]);

        return $this->card($admin, $this->source()->load($admin));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $this->here = $admin;
        $values = $this->source()->load($admin);
        if ($action === self::HELP) {
            return $this->help($admin);
        }
        if ($action === self::PART) {
            $admin->remember($this->screen(), [self::OPEN_PART => isset($args[0]) && ctype_digit($args[0]) ? (int) $args[0] : null]);

            return $this->card($admin, $values);
        }
        if ($action === self::FIELD) {
            return $this->byName($admin, $args[0] ?? '', $values);
        }
        if (!in_array($action, self::FIELD_ACTIONS, true)) {
            return $this->action($admin, $action, $args, $values);
        }
        $index = self::id($args);
        // A field's button names its field (and a choice its choice): data that names none changes no field 0.
        $field = isset($args[0]) && ctype_digit($args[0]) ? $this->fields()[$index] ?? null : null;
        if ($field === null || !$field->shown($values)) {
            return $this->action($admin, $action, $args, $values);
        }
        $this->focus($admin, $field);

        switch ($action) {
            case 'e':
                if ($field->type === FieldDef::TOGGLE) {
                    // An older window's button carries no value: it sets the opposite of what is kept.
                    $on = isset($args[1]) ? $args[1] === '1' : !(bool) ($values[$field->name] ?? false);

                    return $this->turn($admin, $index, $field, $values, $on, ($args[2] ?? '') === self::ASKED);
                }

                return $this->edit($admin, $index, $field, $values);
            case 'c':
                $keys = array_keys($field->options);
                $choice = isset($args[1]) && ctype_digit($args[1]) ? $keys[self::id($args, 1)] ?? null : null;
                if ($choice === null) {
                    break;
                }

                return $this->store($admin, $field, $values, $this->cast($choice));
            case 'm':
                $keys = array_map(strval(...), array_keys($field->options));
                $n = self::id($args, 1);
                $choice = isset($args[1]) && ctype_digit($args[1]) ? $keys[$n] ?? null : null;
                if ($choice === null) {
                    break;
                }
                $current = self::ticked($field, $values);
                // Ticked (1) or not (0) as the button said; an older window's carries neither and turns it over.
                $on = isset($args[2]) ? $args[2] === '1' : !in_array($choice, $current, true);
                $next = $on ? [...$current, $choice] : array_values(array_diff($current, [$choice]));
                $values = $this->source()->save($admin, $values, $field->name, array_values(array_intersect($keys, $next)));

                return $this->multi($index, $field, $values, intdiv($n, View::CHOICES_MOST) + 1);
            case 'mp':
                return $this->multi($index, $field, $values, self::page($args, 1));
            case 'x':
                if ($field->nullable) {
                    return $this->store($admin, $field, $values, null);
                }
                break;
            case 'd':
                if ($field->defaulted) {
                    return $this->store($admin, $field, $values, $field->default);
                }
                break;
        }

        return $this->card($admin, $values);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $this->here = $admin;
        $values = $this->source()->load($admin);
        $field = $this->fields()[self::id($args)] ?? null;
        if ($action !== 'e' || $field === null) {
            return $this->typed($admin, $action, $args, $answer, $values);
        }
        $value = match ($field->type) {
            FieldDef::NUMBER => $answer->integer() ?? throw ApiRefusal::typed($field->name, Words::NOT_NUMBER),
            FieldDef::MONEY => $answer->amount(Unit::Toman) ?? throw ApiRefusal::typed($field->name, Words::NOT_TOMAN),
            // As typed: the panel's own list reads it, and refuses a part that is no number rather than leave it out.
            FieldDef::LIST => $answer->text(),
            FieldDef::URL => $answer->latin(),
            default => $answer->text(),
        };

        return $this->store($admin, $field, $values, $value);
    }

    /**
     * An action of the card's own (a test of what it sets): the card again by default — `v` among them.
     *
     * @param list<string> $args
     * @param array<string, mixed> $values
     * @throws ApiRefusal
     */
    protected function action(Admin $admin, string $action, array $args, array $values): View
    {
        return $this->card($admin, $values);
    }

    /**
     * An answer to a question of the card's own.
     *
     * @param list<string> $args
     * @param array<string, mixed> $values
     * @throws ApiRefusal
     */
    protected function typed(Admin $admin, string $action, array $args, Answer $answer, array $values): View
    {
        return $this->card($admin, $values);
    }

    /**
     * A switch set to `$on`: saved (toggled()), unless it already is — a second tap, a window older than the value —, or
     * the field asks first and was not asked yet.
     *
     * @param array<string, mixed> $values
     * @throws ApiRefusal
     */
    protected function turn(Admin $admin, int $index, FieldDef $field, array $values, bool $on, bool $asked): View
    {
        if ((bool) ($values[$field->name] ?? false) === $on) {
            $admin->notice('«' . $field->label . '» همین حالا ' . ($on ? 'روشن' : 'خاموش') . ' است');

            return $this->card($admin, $values);
        }
        $ask = $field->asks[(int) $on] ?? null;
        if ($ask !== null && !$asked) {
            // Turning on is green, turning off — customers held back — red.
            return $admin->confirm($ask['question'], Button::to($ask['yes'], $this->screen(), 'e', $index, (int) $on, self::ASKED), Button::to(Words::CANCEL, $this->screen(), self::VIEW), $ask['detail'], danger: !$on);
        }

        return $this->toggled($admin, $field, $values, $on);
    }

    /**
     * Save a switch set to `$on` (a card whose switch moves another field with it answers here).
     *
     * @param array<string, mixed> $values
     * @throws ApiRefusal
     */
    protected function toggled(Admin $admin, FieldDef $field, array $values, bool $on): View
    {
        return $this->store($admin, $field, $values, $on);
    }

    /**
     * The card — or the part of it open (parts()): its warnings, the summary of every field shown with its value (a
     * split card's parts each a section of it), the short explanations, its buttons (on the card, the fields of no part
     * and a button to each part; in a part, its own fields'), and the card's intro (with the long explanations) under the
     * rest — one italic line when short, else folded away.
     *
     * @param array<string, mixed> $values
     */
    protected function card(Admin $admin, array $values): View
    {
        $parts = $this->shownParts($values);
        $open = $admin->state($this->screen())[self::OPEN_PART] ?? null;
        if (is_int($open) && isset($parts[$open])) {
            return $this->inPart($admin, $values, $parts[$open]);
        }

        $view = $this->warned(View::make($this->title(), ...$this->path()), $admin, $values);
        $parted = array_merge(...array_column($this->parts(), 1));
        [$facts, $said, $folded, $buttons] = $this->laid($values, fn(FieldDef $field): bool => !in_array($field->name, $parted, true));
        $view->card($facts);
        $parting = [];
        foreach ($parts as $n => $part) {
            $view->section($part[0])->card($this->laid($values, static fn(FieldDef $field): bool => in_array($field->name, $part[1], true))[0]);
            $parting[] = Button::to($part[0], $this->screen(), self::PART, $n);
        }
        $view->lines(...$said)->tiles(...$buttons)->tiles(...$parting);
        $this->extra($view, $admin, $values);
        if ($this->hinted()) {
            $view->row(Button::to(Words::HELP, $this->screen(), self::HELP));
        }
        $intro = $this->intro();
        if ($folded === [] && $intro !== '' && mb_strlen(Fmt::plain($intro)) <= self::SAID_MOST) {
            $view->note($intro);
        } else {
            $view->help(implode("\n\n", array_filter([$intro, ...$folded], static fn(string $part): bool => $part !== '')));
        }

        return $this->back($admin, $view);
    }

    /**
     * A part of the card, open: its label under the card's path, the card's warnings, its fields' summary, their
     * explanations and buttons, «❔ راهنما» with its fields' hints, and its way back the card.
     *
     * @param array<string, mixed> $values
     * @param array{0: string, 1: list<string>} $part
     */
    private function inPart(Admin $admin, array $values, array $part): View
    {
        $view = $this->warned(View::make($part[0], ...$this->within()), $admin, $values);
        [$facts, $said, $folded, $buttons] = $this->laid($values, static fn(FieldDef $field): bool => in_array($field->name, $part[1], true));

        return $view->card($facts)->lines(...$said)->tiles(...$buttons)
            ->row($this->hinted($part[1]) ? Button::to(Words::HELP, $this->screen(), self::HELP) : null)
            ->help(implode("\n\n", $folded))
            ->back($this->screen(), self::PART);
    }

    /**
     * `$view` with the card's warnings as the values stand, over the rest.
     *
     * @param array<string, mixed> $values
     * @throws ApiRefusal
     */
    private function warned(View $view, Admin $admin, array $values): View
    {
        foreach ($this->warnings($admin, $values) as $warning) {
            $view->line('⚠️ ' . Fmt::e($warning));
        }

        return $view;
    }

    /**
     * Where what the card opens itself is (a part, a field's choices, the help): the card's path and its own name.
     *
     * @return list<string>
     */
    private function within(): array
    {
        return [...$this->path(), Fmt::bare($this->title())];
    }

    /**
     * The fields shown that `$takes` takes, laid out: the summary's facts, the short explanations, the long ones and
     * their buttons.
     *
     * @param array<string, mixed> $values
     * @param \Closure(FieldDef): bool $takes
     * @return array{0: array<string, string>, 1: list<string>, 2: list<string>, 3: list<Button>}
     */
    private function laid(array $values, \Closure $takes): array
    {
        $facts = [];
        $said = [];
        $folded = [];
        $buttons = [];
        foreach ($this->fields() as $index => $field) {
            if (!$field->shown($values) || !$takes($field)) {
                continue;
            }
            if (!$this->chips() || $field->type !== FieldDef::TOGGLE) {
                $facts[isset($facts[$field->label]) ? $field->label . ' (' . Fmt::number($index + 1) . ')' : $field->label] = $this->value($field, $values);
            }
            $explained = $this->explain($field, $values);
            if ($explained !== null && mb_strlen($explained) <= self::SAID_MOST) {
                $said[] = '<i>' . Fmt::e($field->label) . ': ' . Fmt::e($explained) . '</i>';
            } elseif ($explained !== null) {
                $folded[] = '<b>' . Fmt::e($field->label) . '</b>: ' . Fmt::e($explained);
            }
            $buttons[] = $this->fieldButton($index, $field, $values);
        }

        return [$facts, $said, $folded, $buttons];
    }

    /**
     * The parts with a field shown, by their place among parts().
     *
     * @param array<string, mixed> $values
     * @return array<int, array{0: string, 1: list<string>}>
     */
    private function shownParts(array $values): array
    {
        $shown = array_map(static fn(FieldDef $field): string => $field->name, array_filter($this->fields(), static fn(FieldDef $field): bool => $field->shown($values)));

        return array_filter($this->parts(), static fn(array $part): bool => array_intersect($part[1], $shown) !== []);
    }

    /**
     * The field of API name `$name` opened (FIELD): a typed one asked, a choice's choices offered — through `e`, as its
     * button would —; a switch, or none of that name, the card (or the part it is on); one hidden as the values stand,
     * the card — or the part the field that shows it is on, never a part left open from before —, saying what shows it
     * (hiddenBy()) — nothing set for it.
     *
     * @param array<string, mixed> $values
     * @throws ApiRefusal
     */
    private function byName(Admin $admin, string $name, array $values): View
    {
        foreach ($this->fields() as $index => $field) {
            if ($field->name !== $name) {
                continue;
            }
            if (!$field->shown($values)) {
                $by = $this->hider($field, $values);
                if ($by !== null) {
                    $this->focus($admin, $by);
                } else {
                    $admin->remember($this->screen(), [self::OPEN_PART => null]);
                }
                $admin->notice($this->hiddenBy($field, $values));

                return $this->card($admin, $values);
            }
            $this->focus($admin, $field);

            return $field->type === FieldDef::TOGGLE ? $this->card($admin, $values) : $this->act($admin, 'e', [(string) $index]);
        }

        return $this->card($admin, $values);
    }

    /**
     * What shows `$field`, hidden as the values stand (plain text): the first field of its `when` holding none of the
     * values it needs — a switch on or off, a choice by its value's label —, else that it is hidden now.
     *
     * @param array<string, mixed> $values
     */
    private function hiddenBy(FieldDef $field, array $values): string
    {
        $by = $this->hider($field, $values);
        $allowed = $by === null ? [] : $field->when[$by->name];
        // A switch by what turning it shows («با روشن کردن «…»»): its label often says a state itself («… فعال باشد»).
        if ($by?->type === FieldDef::TOGGLE && count($allowed) === 1) {
            return 'این گزینه با ' . ($allowed[0] === true ? 'روشن' : 'خاموش') . ' کردن «' . $by->label . '» دیده می‌شود.';
        }
        if ($by?->type === FieldDef::CHOICE) {
            $options = $by->options;
            $picks = array_map(static fn(int|string|bool $value): string => '«' . ($options[(string) (is_bool($value) ? (int) $value : $value)] ?? (string) $value) . '»', $allowed);

            return 'این گزینه وقتی «' . $by->label . '» روی ' . implode(' یا ', $picks) . ' باشد دیده می‌شود.';
        }

        return 'این گزینه با تنظیمات فعلی دیده نمی‌شود.';
    }

    /**
     * The field of the card that hides `$field` as the values stand: the first of its `when` holding none of the values
     * it needs; null for one the card has not.
     *
     * @param array<string, mixed> $values
     */
    private function hider(FieldDef $field, array $values): ?FieldDef
    {
        foreach ($field->when as $name => $allowed) {
            if (in_array($values[$name] ?? null, $allowed, true)) {
                continue;
            }
            foreach ($this->fields() as $each) {
                if ($each->name === $name) {
                    return $each;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * What is done to `$field` comes back to the part it is on — or to the card, a field of no part: a card that asks
     * a field its own way (BotCard) says so too.
     */
    protected function focus(Admin $admin, FieldDef $field): void
    {
        $at = null;
        foreach ($this->parts() as $n => $part) {
            if (in_array($field->name, $part[1], true)) {
                $at = $n;
                break;
            }
        }
        $admin->remember($this->screen(), [self::OPEN_PART => $at]);
    }

    /**
     * A field's value as the card's summary says it (HTML): the card's own way (shows()), else FieldDef's.
     *
     * @param array<string, mixed> $values
     */
    protected function value(FieldDef $field, array $values): string
    {
        $value = $values[$field->name] ?? null;

        return $this->shows($field, $value) ?? $field->display($value);
    }

    /**
     * A field's button, saying only what it does — its value is the summary's: a switch the state a tap sets (and
     * carries it), any other field «✏️ {label}».
     *
     * @param array<string, mixed> $values
     */
    protected function fieldButton(int $index, FieldDef $field, array $values): Button
    {
        if ($field->type === FieldDef::TOGGLE) {
            $on = (bool) ($values[$field->name] ?? false);
            $label = $this->chips() ? ($on ? '🟢 ' : '⏸ ') . $field->label : Words::turn(!$on, $field->label);

            return Button::to($label, $this->screen(), 'e', $index, $on ? 0 : 1);
        }

        return Button::to(Words::edit($field->label), $this->screen(), 'e', $index);
    }

    /** The card's way back: to where it was opened from (Origin), else its parent. */
    protected function back(Admin $admin, View $view): View
    {
        $to = Origin::back($admin, $this->screen(), $this->parent());

        return $to === $this->parent() ? $view->back($to, $this->parentAction()) : $view->back($to);
    }

    /**
     * Save `$field` as `$value` and show the card as the panel then has it, saying so.
     *
     * @param array<string, mixed> $values
     * @throws ApiRefusal
     */
    protected function store(Admin $admin, FieldDef $field, array $values, mixed $value): View
    {
        $values = $this->source()->save($admin, $values, $field->name, $value);
        $admin->notice('✅ «' . $field->label . '» ذخیره شد');

        return $this->card($admin, $values);
    }

    /**
     * Field `$index`'s question (prompt()), or its choices to pick among — the one in force marked ✅.
     *
     * @param array<string, mixed> $values
     */
    private function edit(Admin $admin, int $index, FieldDef $field, array $values): View
    {
        $current = $values[$field->name] ?? null;
        if ($field->type === FieldDef::CHOICE) {
            $view = $this->choices($field);
            $choices = [];
            foreach (array_keys($field->options) as $n => $value) {
                $chosen = (string) (is_bool($current) ? (int) $current : $current) === (string) $value;
                $choices[] = Button::to(($chosen ? Words::PICKED : '') . $field->options[$value], $this->screen(), 'c', $index, $n);
            }

            return $view->grid(count($choices) > 4 ? 2 : 1, ...$choices)->back($this->screen(), self::VIEW);
        }
        if ($field->type === FieldDef::MULTI) {
            return $this->multi($index, $field, $values, 1);
        }

        return $admin->ask($this->prompt($index, $field, $values));
    }

    /**
     * Field `$index`'s question: its hint, the value now (to copy, in the form an answer takes; a secret only whether one
     * is kept), an example and its limits; «🗑 خالی کردن» for one that may be empty and holds a value, «↩️ پیش‌فرض» for
     * one with a value it comes with and holds another. A card that asks a field its own way starts from it.
     *
     * @param array<string, mixed> $values
     */
    protected function prompt(int $index, FieldDef $field, array $values): Prompt
    {
        $current = $values[$field->name] ?? null;
        $hint = $field->hint;
        if ($field->type === FieldDef::LIST) {
            $hint .= ($hint === '' ? '' : "\n") . 'عددها را با فاصله یا «،» جدا کنید.';
        }
        $prompt = (new Prompt($field->label, $this->screen(), 'e', [$index], $hint, Button::to(Words::CANCEL, $this->screen(), self::VIEW)))
            ->current($field->current($current, $this->shows($field, $current)), $field->type !== FieldDef::SECRET)
            ->example($field->exampleOf())
            ->limits($field->limits);
        if ($field->nullable && $current !== null && $current !== '' && $current !== []) {
            $prompt->clearable($this->screen(), 'x', $index);
        }
        if ($field->defaulted && $current !== $field->default) {
            $prompt->resettable($this->screen(), 'd', $index);
        }

        return $prompt;
    }

    /**
     * Field `$index`'s choices to tick, page `$page` of them past one keyboard's worth: each button the tick it sets.
     *
     * @param array<string, mixed> $values
     */
    private function multi(int $index, FieldDef $field, array $values, int $page): View
    {
        $current = self::ticked($field, $values);
        $view = $this->choices($field);
        $choices = [];
        foreach (array_keys($field->options) as $n => $value) {
            $ticked = in_array((string) $value, $current, true);
            $choices[] = Button::to(Words::tick($ticked) . $field->options[$value], $this->screen(), 'm', $index, $n, $ticked ? 0 : 1);
        }

        return $view->paged(count($choices) > 4 ? 2 : 1, $choices, $page, fn(int $at): array => [$this->screen(), 'mp', [$index, $at]])->back($this->screen(), self::VIEW);
    }

    /**
     * The choices of a field of several ticked now, as strings.
     *
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private static function ticked(FieldDef $field, array $values): array
    {
        return array_map(strval(...), is_array($values[$field->name] ?? null) ? array_values($values[$field->name]) : []);
    }

    /**
     * The screen a field's choices are picked on: the field's name under the card's, its hint first — and, with none to
     * pick among yet (no server in the shop), that said.
     */
    private function choices(FieldDef $field): View
    {
        $view = View::make($field->label, ...$this->within());
        if ($field->hint !== '') {
            $view->line($field->hint);
        }

        return $field->options === [] ? $view->line(self::NOTHING_TO_PICK) : $view;
    }

    /**
     * Whether there is anything to show under «❔ راهنما»: a field's hint or the card's notes — or, for the part of
     * fields `$names`, a hint of one of them.
     *
     * @param list<string>|null $names
     */
    private function hinted(?array $names = null): bool
    {
        if ($names === null && $this->notes() !== []) {
            return true;
        }

        return array_filter($this->fields(), static fn(FieldDef $field): bool => $field->hint !== '' && ($names === null || in_array($field->name, $names, true))) !== [];
    }

    /**
     * Every field's hint and the card's notes: what each switch and number does, as the panel says it — or, a part open,
     * its own fields' alone (its way back, `v`, is the part again).
     */
    private function help(Admin $admin): View
    {
        $open = $admin->state($this->screen())[self::OPEN_PART] ?? null;
        $part = is_int($open) ? ($this->parts()[$open] ?? null) : null;
        $view = $part === null
            ? View::make('❔ ' . Fmt::bare($this->title()), ...$this->within())
            : View::make('❔ ' . Fmt::bare($part[0]), ...[...$this->within(), Fmt::bare($part[0])]);
        foreach ($this->fields() as $field) {
            if ($field->hint !== '' && ($part === null || in_array($field->name, $part[1], true))) {
                $view->line('<b>' . Fmt::e($field->label) . '</b>')->line($field->hint)->line();
            }
        }
        foreach ($part === null ? $this->notes() : [] as $note) {
            $view->line('• ' . Fmt::e($note));
        }

        return $view->back($this->screen(), self::VIEW);
    }

    /** A choice's value as the API takes it: "true" / "false" (a switch as two choices) a bool; any other as is. */
    private function cast(int|string $choice): int|string|bool
    {
        return match (true) {
            $choice === 'true' => true,
            $choice === 'false' => false,
            default => $choice,
        };
    }
}
