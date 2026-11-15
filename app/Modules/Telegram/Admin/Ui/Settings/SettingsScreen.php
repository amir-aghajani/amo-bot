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
use App\Support\Unit;

/**
 * A settings card of the panel's as a management screen: its fields (FieldDef), each a button with its value — the
 * ones its conditions hide left out, and where the panel's hint follows what is set, that line under it (explain()) —,
 * the card's warnings over them, and «❔ راهنما» with every field's hint and the card's notes (the panel's info box), so a
 * switch set at a tap still says what it does. Read from and saved to the panel's API (SettingsSource) a field at a
 * time, as the panel saves its card: a switch is set at a tap — its button carries the value it sets, and a switch that
 * holds customers back (the bot off) asks first (FieldDef::asks()) —, a choice is picked among buttons, the rest is typed.
 * The panel's own check answers each change: a value it refuses is said, and nothing is kept. Opened from a menu other
 * than its parent (Origin: «🤖 تنظیمات ربات»), its «⬅️ بازگشت» goes back there.
 *
 * Actions: `e` (i: edit field i; a switch's i, value — 1 on, 0 off — and `y` once asked), `c` (i, n: field i's choice
 * n), `m` (i, n, 1|0: field i's choice n ticked or not among several), `mp` (i, page: those choices' page), `x` (i:
 * field i emptied), `v` (the card again, its origin kept: a question's «✖️ لغو», a choice's way back), `help`.
 */
abstract class SettingsScreen extends BaseScreen
{
    /** The card again where it was left, its origin kept: the way back of what it opens itself. */
    public const VIEW = 'v';

    /** Every field's hint and the card's notes. */
    public const HELP = 'help';

    /** The field actions; any other is the card's own (action()). */
    private const FIELD_ACTIONS = ['e', 'c', 'm', 'mp', 'x'];

    /** A switch's third argument once its question was answered yes. */
    private const ASKED = 'y';

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

        return $this->card($admin, $this->source()->load($admin));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $this->here = $admin;
        $values = $this->source()->load($admin);
        if ($action === self::HELP) {
            return $this->help();
        }
        if (!in_array($action, self::FIELD_ACTIONS, true)) {
            return $this->action($admin, $action, $args, $values);
        }
        $index = self::id($args);
        $field = $this->fields()[$index] ?? null;
        if ($field === null || !$field->shown($values)) {
            return $this->action($admin, $action, $args, $values);
        }

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
                $choice = $keys[self::id($args, 1)] ?? null;
                if ($choice === null) {
                    break;
                }

                return $this->store($admin, $field, $values, $this->cast($choice));
            case 'm':
                $keys = array_map(strval(...), array_keys($field->options));
                $n = self::id($args, 1);
                $choice = $keys[$n] ?? null;
                $current = array_map(strval(...), is_array($values[$field->name] ?? null) ? $values[$field->name] : []);
                if ($choice === null) {
                    break;
                }
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
            FieldDef::NUMBER => $answer->integer() ?? throw ApiRefusal::typed($field->name, 'یک عدد بنویسید.'),
            FieldDef::MONEY => $answer->amount(Unit::Toman) ?? throw ApiRefusal::typed($field->name, 'مبلغ را به تومان بنویسید، مثل ۵۰۰۰۰ یا «۵۰ هزار تومان».'),
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
            return $admin->confirm($ask['question'], Button::to($ask['yes'], $this->screen(), 'e', $index, (int) $on, self::ASKED), Button::to('✖️ انصراف', $this->screen(), self::VIEW), $ask['detail']);
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
     * @param array<string, mixed> $values
     */
    protected function card(Admin $admin, array $values): View
    {
        $view = View::make($this->title(), ...$this->path());
        if ($this->intro() !== '') {
            $view->line($this->intro());
        }
        foreach ($this->warnings($admin, $values) as $warning) {
            $view->line('⚠️ ' . Fmt::e($warning));
        }
        $buttons = [];
        foreach ($this->fields() as $index => $field) {
            if (!$field->shown($values)) {
                continue;
            }
            $value = $values[$field->name] ?? null;
            $view->line('• ' . Fmt::e($field->label) . ': ' . ($this->shows($field, $value) ?? $field->display($value)));
            $explained = $this->explain($field, $values);
            if ($explained !== null) {
                $view->line('   <i>' . Fmt::e($explained) . '</i>');
            }
            $buttons[] = $this->fieldButton($index, $field, $values);
        }
        $view->grid(1, ...$buttons);
        $this->extra($view, $admin, $values);
        if ($this->hinted()) {
            $view->row(Button::to('❔ راهنما', $this->screen(), self::HELP));
        }

        return $this->back($admin, $view);
    }

    /**
     * A field's button: its label and value; a switch's carries the value a tap sets.
     *
     * @param array<string, mixed> $values
     */
    protected function fieldButton(int $index, FieldDef $field, array $values): Button
    {
        $value = $values[$field->name] ?? null;
        $shown = $this->shows($field, $value);
        $brief = $shown !== null ? Fmt::cut(Fmt::plain($shown), 24) : $field->brief($value);

        return $field->type === FieldDef::TOGGLE
            ? Button::to($field->label . ': ' . $brief, $this->screen(), 'e', $index, (bool) $value ? 0 : 1)
            : Button::to($field->label . ': ' . $brief, $this->screen(), 'e', $index);
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
     * Field `$index`'s question: its hint and the value now, «🗑 خالی کردن» for one that may be empty.
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
                $choices[] = Button::to(($chosen ? '● ' : '') . $field->options[$value], $this->screen(), 'c', $index, $n);
            }

            return $view->grid(count($choices) > 4 ? 2 : 1, ...$choices)->back($this->screen(), self::VIEW);
        }
        if ($field->type === FieldDef::MULTI) {
            return $this->multi($index, $field, $values, 1);
        }

        $hint = [];
        if ($field->hint !== '') {
            $hint[] = $field->hint;
        }
        $hint[] = 'الان: ' . ($field->type === FieldDef::TEXTAREA && is_string($current) && $current !== '' ? '<blockquote>' . Fmt::e($current) . '</blockquote>' : ($this->shows($field, $current) ?? $field->display($current)));
        if ($field->type === FieldDef::LIST) {
            $hint[] = 'عددها را با فاصله یا «،» جدا کنید.';
        }
        $prompt = new Prompt($field->label, $this->screen(), 'e', [$index], implode("\n", $hint), Button::to('✖️ لغو', $this->screen(), self::VIEW));
        if ($field->nullable && $current !== null && $current !== '' && $current !== []) {
            $prompt->choices(Button::to('🗑 خالی کردن', $this->screen(), 'x', $index));
        }

        return $admin->ask($prompt);
    }

    /**
     * Field `$index`'s choices to tick, page `$page` of them past one keyboard's worth: each button the tick it sets.
     *
     * @param array<string, mixed> $values
     */
    private function multi(int $index, FieldDef $field, array $values, int $page): View
    {
        $current = array_map(strval(...), is_array($values[$field->name] ?? null) ? $values[$field->name] : []);
        $view = $this->choices($field);
        $choices = [];
        foreach (array_keys($field->options) as $n => $value) {
            $ticked = in_array((string) $value, $current, true);
            $choices[] = Button::to(($ticked ? '✅ ' : '◻️ ') . $field->options[$value], $this->screen(), 'm', $index, $n, $ticked ? 0 : 1);
        }

        return $view->paged(count($choices) > 4 ? 2 : 1, $choices, $page, fn(int $at): array => [$this->screen(), 'mp', [$index, $at]])->back($this->screen(), self::VIEW);
    }

    /** The screen a field's choices are picked on: the field's name under the card's, its hint first. */
    private function choices(FieldDef $field): View
    {
        $view = View::make($field->label, ...[...$this->path(), $this->title()]);

        return $field->hint !== '' ? $view->line($field->hint) : $view;
    }

    /** Whether the card has hints or notes to show under «❔ راهنما». */
    private function hinted(): bool
    {
        return $this->notes() !== [] || array_filter($this->fields(), static fn(FieldDef $field): bool => $field->hint !== '') !== [];
    }

    /** Every field's hint and the card's notes: what each switch and number does, as the panel says it. */
    private function help(): View
    {
        $view = View::make('❔ ' . $this->title(), ...[...$this->path(), $this->title()]);
        foreach ($this->fields() as $field) {
            if ($field->hint !== '') {
                $view->line('<b>' . Fmt::e($field->label) . '</b>')->line($field->hint)->line();
            }
        }
        foreach ($this->notes() as $note) {
            $view->line('• ' . Fmt::e($note));
        }

        return $view->back($this->screen(), self::VIEW);
    }

    /** A choice's value as the API takes it: a switch-like "0"/"1" stays a string; a number is a number. */
    private function cast(int|string $choice): int|string|bool
    {
        return match (true) {
            $choice === 'true' => true,
            $choice === 'false' => false,
            default => $choice,
        };
    }
}
