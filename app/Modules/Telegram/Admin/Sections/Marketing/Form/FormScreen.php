<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Form;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * A panel's create/edit dialog as a wizard in the bot. A new one is walked through a field (Step) at a time, each step a
 * question — its title — with where the walk is («گام ۲ از ۶» and a bar), the field's hint, its value now when it has
 * one, and the ways on: its choices (the one in force ✅) or a typed answer with an example, its limits and quick values;
 * «⬅️ گام قبل» back to the step before, «⏭ رد شدن» past an optional one (or «⏭ همین بماند» keeping a value it holds),
 * «✖️ انصراف» out. Past the last step, the review card: every field with its value — the panel's «محدودیت‌های بیشتر»
 * (fields not walked) a section of their own —, the panel's refusals marked on their fields, «✏️» for each and one save,
 * «✔️ ساختن»: the panel's own POST. Editing a row starts at its review, from the row as the panel has it now (row()); its
 * save («💾 ذخیره», the panel's PUT) is offered once something changed. A field's choices — the shop's plans, servers —
 * come View::CHOICES_MOST a page, the page each field is on kept in the state.
 *
 * Actions: `new`, `edit` (id), `sum` (the review), `ask` (i: asked from the review), `prev` (i: the step before step
 * i), `skip` (i: step i left as it is), `clr` (i: emptied), `pick` (i, n: choice n; a switch's 0 on, 1 off), `mt` (i,
 * n, 1|0: choice n ticked or not), `all` (i), `done` (i: the ticks done), `q` (i, n: quick value n), `pg` (i, n: the
 * choices' page n), `save`; the answer to a typed field is `v` (i). A button carries the step it is on — and a tick the
 * state it sets —, so a second tap, or one from an older window, lands where the first did.
 */
abstract class FormScreen extends BaseScreen
{
    /** The screen's id, as MarketingScreens registers it. */
    abstract protected function screen(): string;

    /**
     * The title (plain text): «➕ افزودن …» for a new one (`$id` 0), «✏️ ویرایش …» for a row.
     *
     * @param array<string, mixed> $draft
     */
    abstract protected function title(int $id, array $draft): string;

    /**
     * @return list<string> The sections it is in.
     */
    abstract protected function path(): array;

    /**
     * Its fields, in the panel's order — always the same list, so a field's place is its number in a button.
     *
     * @return list<Step>
     */
    abstract protected function steps(Admin $admin): array;

    /**
     * A new one's draft: every field's starting value.
     *
     * @return array<string, mixed>
     */
    abstract protected function blank(Admin $admin): array;

    /**
     * Where «✖️ انصراف» goes for row `$id` (0: a new one): its screen, action and arguments.
     *
     * @return array{0: string, 1: string, 2: list<int|string>}
     */
    abstract protected function back(int $id): array;

    /**
     * The draft as the API takes it.
     *
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    abstract protected function body(array $draft): array;

    /**
     * Save: the panel's POST for a new one (`$id` 0), its PUT for row `$id`; the row it answers.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    abstract protected function store(Admin $admin, int $id, array $body): array;

    /**
     * What is shown once saved (the row's screen), saying so.
     *
     * @param array<string, mixed> $row
     * @throws ApiRefusal
     */
    abstract protected function done(Admin $admin, array $row, bool $created): View;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    /**
     * Row `$id` as the panel has it now — read afresh, never a list's kept page: its PUT sends the whole form back —, to
     * edit; null for none.
     *
     * @return array<string, mixed>|null
     * @throws ApiRefusal
     */
    protected function row(Admin $admin, int $id): ?array
    {
        return null;
    }

    /**
     * A row's draft, to edit it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function draftOf(array $row): array
    {
        return $row;
    }

    /**
     * The heading of the fields not walked on the review (the panel's «محدودیت‌های بیشتر»); '' to keep them on the one
     * card with the rest.
     */
    protected function laterTitle(): string
    {
        return '';
    }

    /**
     * Notes under the review (HTML): what the panel says beside its form.
     *
     * @param array<string, mixed> $draft
     * @return list<string>
     */
    protected function notes(Admin $admin, int $id, array $draft): array
    {
        return [];
    }

    public function show(Admin $admin, array $args): View
    {
        if (!$this->open($admin)) {
            return $this->leave($admin);
        }
        $admin->remember($this->screen(), ['walk' => false]);

        return $this->review($admin, $this->steps($admin));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'new') {
            $admin->forget($this->screen());
            $admin->remember($this->screen(), ['id' => 0, 'draft' => $this->blank($admin), 'walk' => true, 'errors' => []]);

            return $this->next($admin, $this->steps($admin), -1);
        }
        if ($action === 'edit') {
            $id = self::id($args);
            $row = $this->row($admin, $id);
            if ($row === null) {
                return $this->leave($admin);
            }
            $draft = $this->draftOf($row);
            $admin->forget($this->screen());
            $admin->remember($this->screen(), ['id' => $id, 'draft' => $draft, 'was' => $draft, 'walk' => false, 'errors' => []]);

            return $this->review($admin, $this->steps($admin));
        }
        if (!$this->open($admin)) {
            return $this->leave($admin);
        }

        $steps = $this->steps($admin);
        if ($action === 'save') {
            return $this->save($admin, $steps);
        }
        if ($action === 'sum') {
            $admin->remember($this->screen(), ['walk' => false]);

            return $this->review($admin, $steps);
        }
        $index = self::id($args);
        $step = $steps[$index] ?? null;
        $draft = $this->draft($admin);
        if ($step === null || !$step->shown($draft)) {
            return $this->review($admin, $steps);
        }

        switch ($action) {
            case 'ask':
                $admin->remember($this->screen(), ['walk' => false]);

                return $this->ask($admin, $steps, $index);
            case 'prev':
                return $this->ask($admin, $steps, $this->walking($admin) ? ($this->previous($steps, $draft, $index) ?? $index) : $index);
            case 'skip':
                return $this->after($admin, $steps, $index);
            case 'clr':
                $this->put($admin, $step, null);

                return $this->after($admin, $steps, $index);
            case 'pick':
                $keys = $step->type === Step::TOGGLE ? [1, 0] : array_keys($step->options());
                $key = $keys[self::id($args, 1)] ?? null;
                if ($key === null) {
                    break;
                }
                $this->put($admin, $step, $step->type === Step::TOGGLE ? $key === 1 : ($key === '' ? null : $key));

                return $this->after($admin, $steps, $index);
            case 'pg':
                $pages = Json::part($admin->state($this->screen()), 'pages');
                $pages[$index] = self::page($args, 1);
                $admin->remember($this->screen(), ['pages' => $pages]);

                return $this->ask($admin, $steps, $index);
            case 'mt':
                $keys = array_keys($step->options());
                $key = $keys[self::id($args, 1)] ?? null;
                if ($key !== null) {
                    $chosen = is_array($draft[$step->key] ?? null) ? $draft[$step->key] : [];
                    // Ticked (1) or not (0) as the button said — a tap twice is one; an older window's carries neither and
                    // turns it over.
                    $tick = isset($args[2]) ? $args[2] === '1' : !in_array($key, $chosen, true);
                    $chosen = $tick ? [...$chosen, $key] : array_values(array_diff($chosen, [$key]));
                    // In the choices' own order, each once.
                    $this->put($admin, $step, array_values(array_filter($keys, static fn(int|string $one): bool => in_array($one, $chosen, true))));
                }

                return $this->ask($admin, $steps, $index);
            case 'all':
                $this->put($admin, $step, $step->all !== '' ? null : []);

                return $this->ask($admin, $steps, $index);
            case 'done':
                $chosen = $draft[$step->key] ?? null;
                if (is_array($chosen) && $chosen === []) {
                    if ($step->all === '') {
                        $admin->alert('دست‌کم یکی را انتخاب کنید.');

                        return $this->ask($admin, $steps, $index);
                    }
                    $this->put($admin, $step, null);
                }

                return $this->after($admin, $steps, $index);
            case 'q':
                $quick = $step->quick[self::id($args, 1)] ?? null;
                if ($quick === null) {
                    break;
                }
                $this->put($admin, $step, $quick[1]);

                return $this->after($admin, $steps, $index);
        }

        return $this->review($admin, $steps);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if (!$this->open($admin)) {
            return $this->leave($admin);
        }
        $steps = $this->steps($admin);
        $index = self::id($args);
        $step = $steps[$index] ?? null;
        if ($action !== 'v' || $step === null) {
            return $this->review($admin, $steps);
        }
        $this->put($admin, $step, $step->read($answer));

        return $this->after($admin, $steps, $index);
    }

    /**
     * The draft as it stands.
     *
     * @return array<string, mixed>
     */
    protected function draft(Admin $admin): array
    {
        $draft = $admin->state($this->screen())['draft'] ?? [];

        return is_array($draft) ? $draft : [];
    }

    /**
     * The review card: every field shown with its value — the panel's refusals marked on theirs and said under the card —,
     * the fields not walked in a section of their own (laterTitle()), the notes, «✏️» for each field (a switch the state
     * a tap sets), and the save — «✔️ ساختن» for a new one, «💾 ذخیره» for a row once something changed — beside
     * «✖️ انصراف»; a row unchanged has the way back instead.
     *
     * @param list<Step> $steps
     */
    private function review(Admin $admin, array $steps): View
    {
        $state = $admin->state($this->screen());
        $id = (int) ($state['id'] ?? 0);
        $draft = $this->draft($admin);
        $errors = Json::part($state, 'errors');
        $view = View::make($this->title($id, $draft), ...$this->path());
        if ($id === 0) {
            $view->line(Marketing::REVIEW);
        }

        $parted = $this->laterTitle() !== '';
        $facts = [];
        $later = [];
        $refused = [];
        $fields = [];
        $buttons = [];
        foreach ($steps as $index => $step) {
            if (!$step->shown($draft)) {
                continue;
            }
            $fields[] = $step->field;
            $value = $draft[$step->key] ?? null;
            $error = is_string($errors[$step->field] ?? null) ? $errors[$step->field] : null;
            $label = ($error !== null ? '⚠️ ' : '') . $step->label;
            if ($parted && !$step->walked) {
                $later[$label] = $step->display($value);
            } else {
                $facts[$label] = $step->display($value);
            }
            if ($error !== null) {
                $refused[] = '⚠️ ' . Fmt::e($step->label) . ': ' . Fmt::e($error);
            }
            $buttons[] = $this->button($index, $step, $value);
        }
        foreach ($errors as $field => $message) {
            if (!in_array($field, $fields, true) && is_string($message)) {
                $refused[] = '⚠️ ' . Fmt::e($message);
            }
        }
        $view->card($facts)->lines(...$refused);
        if ($later !== []) {
            $view->section($this->laterTitle())->card($later);
        }
        foreach ($this->notes($admin, $id, $draft) as $note) {
            $view->note($note);
        }
        $changed = $id > 0 && $draft != ($state['was'] ?? null);
        if ($id > 0) {
            $view->line()->line($changed ? '<b>' . Fmt::e(Marketing::UNSAVED) . '</b>' : '<i>' . Fmt::e(Marketing::UNCHANGED) . '</i>');
        }
        $view->tiles(...$buttons);
        [$screen, $action, $args] = $this->back($id);
        if ($id > 0 && !$changed) {
            return $view->back($screen, $action, ...$args);
        }

        return $view->row(Button::to($id === 0 ? Words::CREATE : Words::SAVE, $this->screen(), 'save')->success(), Button::to(Words::CANCEL, $screen, $action, ...$args));
    }

    /**
     * A field's button on the review, saying only what it does: a switch the state a tap sets (pick 0: on, 1: off —
     * never "the opposite of now": a tap twice is one), any other «✏️ {label}».
     */
    private function button(int $index, Step $step, mixed $value): Button
    {
        if ($step->type !== Step::TOGGLE) {
            return Button::to(Words::edit($step->label), $this->screen(), 'ask', $index);
        }
        $on = (bool) $value;
        $label = $step->active ? ($on ? Words::DEACTIVATE : Words::ACTIVATE) : Words::turn(!$on, $step->label);

        return Button::to($label, $this->screen(), 'pick', $index, $on ? 1 : 0);
    }

    /**
     * Ask field `$index`: its question, where the walk is, its hint and value now, then its choices as buttons or a typed
     * answer — with the ways back, on and out.
     *
     * @param list<Step> $steps
     */
    private function ask(Admin $admin, array $steps, int $index): View
    {
        $step = $steps[$index];
        $state = $admin->state($this->screen());
        $walk = $this->walking($admin);
        $id = (int) ($state['id'] ?? 0);
        $draft = $this->draft($admin);
        $value = $draft[$step->key] ?? null;
        $trail = [...$this->path(), Fmt::bare($this->title($id, $draft))];

        $head = [];
        if ($walk) {
            $head[] = $this->progress($steps, $draft, $index);
        }
        if ($step->hint !== '') {
            $head[] = Fmt::e($step->hint);
        }
        [$screen, $action, $args] = $this->back($id);
        $cancel = $walk ? Button::to(Words::CANCEL, $screen, $action, ...$args) : Button::to(Words::CANCEL, $this->screen(), 'sum');
        $previous = $walk && $this->previous($steps, $draft, $index) !== null ? Button::to(Words::PREVIOUS_STEP, $this->screen(), 'prev', $index) : null;

        if ($step->tapped()) {
            $view = View::make('✍️ ' . $step->question, ...$trail)->transient()->lines(...$head);
            $chips = [];
            // The page first shown: the one the field was left on, else the one with its choice.
            $at = 0;
            if ($step->type === Step::TOGGLE) {
                $words = $step->active ? ['فعال', 'غیرفعال'] : ['بله', 'خیر'];
                $chips = [
                    Button::to(((bool) $value ? Words::PICKED : '') . $words[0], $this->screen(), 'pick', $index, 0),
                    Button::to((!(bool) $value ? Words::PICKED : '') . $words[1], $this->screen(), 'pick', $index, 1),
                ];
            } elseif ($step->type === Step::CHOICE) {
                foreach (array_keys($step->options()) as $n => $key) {
                    $chosen = $value === null ? $key === '' : (string) $key === (string) (is_scalar($value) ? $value : '');
                    $at = $chosen ? $n : $at;
                    $chips[] = Button::to(($chosen ? Words::PICKED : '') . $step->options()[$key], $this->screen(), 'pick', $index, $n);
                }
            } else {
                $chosen = is_array($value) ? $value : null;
                if ($step->all !== '') {
                    $view->row(Button::to(($chosen === null ? Words::PICKED : '') . $step->all, $this->screen(), 'all', $index));
                }
                foreach (array_keys($step->options()) as $n => $key) {
                    $ticked = $chosen !== null && in_array($key, $chosen, true);
                    $chips[] = Button::to(Words::tick($ticked) . $step->options()[$key], $this->screen(), 'mt', $index, $n, $ticked ? 0 : 1);
                }
            }
            if ($chips === []) {
                $view->line('<i>چیزی برای انتخاب نیست.</i>');
            }
            $pages = Json::part($state, 'pages');
            $page = is_int($pages[$index] ?? null) ? $pages[$index] : intdiv($at, View::CHOICES_MOST) + 1;
            $view->paged(count($chips) > 4 ? 2 : 1, $chips, $page, fn(int $to): array => [$this->screen(), 'pg', [$index, $to]]);
            if ($step->type === Step::MULTI) {
                $view->row(Button::to(Marketing::TICKED, $this->screen(), 'done', $index)->success());
            }

            return $view->row($previous)->row($cancel);
        }

        $prompt = new Prompt($step->question, $this->screen(), 'v', [$index], implode("\n", $head), $cancel);
        if (!$walk || !Step::blank($value)) {
            $prompt->current($step->typed($value));
        }
        $prompt->example($step->exampleOf())->limits($step->limits());
        $quick = [];
        foreach ($step->quick as $n => [$label]) {
            $quick[] = Button::to($label, $this->screen(), 'q', $index, $n);
        }
        if ($step->optional && !Step::blank($value)) {
            $prompt->clearable($this->screen(), 'clr', $index);
        }
        $onward = $walk && ($step->optional || !Step::blank($value)) ? Button::to(Step::blank($value) ? Words::SKIP : Marketing::KEEP, $this->screen(), 'skip', $index) : null;
        // A question asked from inside the form says so, whatever window came before it.
        $admin->here($trail);

        return $admin->ask($prompt->choices(...$quick)->ways($previous, $onward));
    }

    /**
     * Where the walk is at step `$index`, as its first line: «گام ۲ از ۶» and a bar.
     *
     * @param list<Step> $steps
     * @param array<string, mixed> $draft
     */
    private function progress(array $steps, array $draft, int $index): string
    {
        $walked = $this->walked($steps, $draft);
        $place = array_search($index, $walked, true);
        $at = ($place === false ? 0 : $place) + 1;

        return Fmt::e(Words::step($at, count($walked))) . '  ' . str_repeat('▰', $at) . str_repeat('▱', max(0, count($walked) - $at));
    }

    /**
     * The places of the steps asked on the way through, as the draft stands.
     *
     * @param list<Step> $steps
     * @param array<string, mixed> $draft
     * @return list<int>
     */
    private function walked(array $steps, array $draft): array
    {
        return array_keys(array_filter($steps, static fn(Step $step): bool => $step->walked && $step->shown($draft)));
    }

    /**
     * The step asked on the way through before step `$index`; null for the first.
     *
     * @param list<Step> $steps
     * @param array<string, mixed> $draft
     */
    private function previous(array $steps, array $draft, int $index): ?int
    {
        $before = array_filter($this->walked($steps, $draft), static fn(int $at): bool => $at < $index);

        return $before === [] ? null : max($before);
    }

    /** Whether the form is being walked through (a new one, before its review). */
    private function walking(Admin $admin): bool
    {
        return (bool) ($admin->state($this->screen())['walk'] ?? false);
    }

    /**
     * After field `$index` is set: the next field on the way through, else the review.
     *
     * @param list<Step> $steps
     */
    private function after(Admin $admin, array $steps, int $index): View
    {
        return $this->walking($admin) ? $this->next($admin, $steps, $index) : $this->review($admin, $steps);
    }

    /**
     * The first field after `$index` asked on the way through, shown as the draft stands; the review past the last.
     *
     * @param list<Step> $steps
     */
    private function next(Admin $admin, array $steps, int $index): View
    {
        foreach ($this->walked($steps, $this->draft($admin)) as $at) {
            if ($at > $index) {
                return $this->ask($admin, $steps, $at);
            }
        }
        $admin->remember($this->screen(), ['walk' => false]);

        return $this->review($admin, $steps);
    }

    /**
     * Save the draft: the row's screen, or the review with the panel's refusals marked on their fields.
     *
     * @param list<Step> $steps
     * @throws ApiRefusal anything but a form refused
     */
    private function save(Admin $admin, array $steps): View
    {
        $id = (int) ($admin->state($this->screen())['id'] ?? 0);
        try {
            $row = $this->store($admin, $id, $this->body($this->draft($admin)));
        } catch (ApiRefusal $refusal) {
            if ($refusal->status !== 422 || $refusal->errors === []) {
                throw $refusal;
            }
            $errors = [];
            foreach ($refusal->errors as $field => $messages) {
                if ($messages !== []) {
                    $errors[$field] = $messages[0];
                }
            }
            // A field several steps share (a code's `value`: its percent or its amount) is labelled by the one shown.
            $draft = $this->draft($admin);
            $labels = [];
            foreach ($steps as $step) {
                if ($step->shown($draft)) {
                    $labels[$step->field] ??= $step->label;
                }
            }
            $admin->remember($this->screen(), ['errors' => $errors, 'walk' => false]);
            $admin->alert($refusal->lines($labels));

            return $this->review($admin, $steps);
        }
        // Ended the moment the panel answers, before anything that can fail after it (a file Telegram refuses, the next
        // screen's read): the chat's turn saves this whatever comes next, and a second tap finds no draft to save again.
        $admin->forget($this->screen());

        return $this->done($admin, $row, $id === 0);
    }

    /** Set a field in the draft, its refusal gone. */
    private function put(Admin $admin, Step $step, mixed $value): void
    {
        $state = $admin->state($this->screen());
        $draft = $this->draft($admin);
        $draft[$step->key] = $value;
        $errors = Json::part($state, 'errors');
        unset($errors[$step->field]);
        $admin->remember($this->screen(), ['draft' => $draft, 'errors' => $errors]);
    }

    /** Whether a draft is open (a button of a form saved or left since is not). */
    private function open(Admin $admin): bool
    {
        return is_array($admin->state($this->screen())['draft'] ?? null);
    }

    /** For a form no longer open (or a row gone): where a new one's «✖️ انصراف» goes, the list. */
    private function leave(Admin $admin): View
    {
        [$screen, $action, $args] = $this->back(0);

        return $admin->open($screen, $action, $args);
    }
}
