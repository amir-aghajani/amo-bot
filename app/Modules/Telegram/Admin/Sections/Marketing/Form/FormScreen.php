<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Form;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * A panel's create/edit dialog as a wizard in the bot: a draft kept in the screen's state, asked a field (Step) at a
 * time — «⏭ رد شدن» for an optional one —, then a summary of every field with «✏️» for each, and one save: the panel's
 * own POST or PUT, whose refusals are marked beside their fields on the summary. Editing a row starts at its summary,
 * from the row as the panel has it now (row()). A field's choices — the shop's plans, servers — come View::CHOICES_MOST
 * a page, the page each field is on kept in the state.
 *
 * Actions: `new`, `edit` (id), `sum`, `ask` (i), `skip` (i), `clr` (i), `pick` (i, n: choice n; a switch's 0 on, 1
 * off), `mt` (i, n), `all` (i), `done` (i), `q` (i, n: quick value n), `pg` (i, n: the choices' page n), `save`; the
 * answer to a typed field is `v` (i).
 */
abstract class FormScreen extends BaseScreen
{
    /** The screen's id, as MarketingScreens registers it. */
    abstract protected function screen(): string;

    /**
     * The title (plain text): the panel's dialog's — «➕ ساخت …» for a new one (`$id` 0), «✏️ ویرایش …» for a row.
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

    /** The save of a new one: «✔️ ساختن», or «✔️ افزودن» for what the panel adds («➕ افزودن سطح»). */
    protected function createLabel(): string
    {
        return '✔️ ساختن';
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
     * Notes under the summary (HTML): what the panel says beside its form.
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
            return $this->leave($admin, 0);
        }
        $admin->remember($this->screen(), ['walk' => false]);

        return $this->summary($admin, $this->steps($admin));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'new') {
            $admin->forget($this->screen());
            $admin->remember($this->screen(), ['id' => 0, 'draft' => $this->blank($admin), 'walk' => true, 'errors' => []]);

            return $this->next($admin, $this->steps($admin), -1);
        }
        if ($action === 'edit') {
            $row = $this->row($admin, self::id($args));
            if ($row === null) {
                return $this->leave($admin, 0);
            }
            $admin->forget($this->screen());
            $admin->remember($this->screen(), ['id' => self::id($args), 'draft' => $this->draftOf($row), 'walk' => false, 'errors' => []]);

            return $this->summary($admin, $this->steps($admin));
        }
        if (!$this->open($admin)) {
            return $this->leave($admin, 0);
        }

        $steps = $this->steps($admin);
        $index = self::id($args);
        $step = $steps[$index] ?? null;
        $draft = $this->draft($admin);
        if ($action === 'save') {
            return $this->save($admin, $steps);
        }
        if ($step === null || !$step->shown($draft)) {
            return $this->summary($admin, $steps);
        }

        switch ($action) {
            case 'ask':
                $admin->remember($this->screen(), ['walk' => false]);

                return $this->ask($admin, $steps, $index);
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
                $pages = $admin->state($this->screen())['pages'] ?? [];
                $pages = is_array($pages) ? $pages : [];
                $pages[$index] = self::page($args, 1);
                $admin->remember($this->screen(), ['pages' => $pages]);

                return $this->ask($admin, $steps, $index);
            case 'mt':
                $keys = array_keys($step->options());
                $key = $keys[self::id($args, 1)] ?? null;
                if ($key !== null) {
                    $chosen = is_array($draft[$step->key] ?? null) ? $draft[$step->key] : [];
                    $chosen = in_array($key, $chosen, true) ? array_values(array_diff($chosen, [$key])) : [...$chosen, $key];
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

        return $this->summary($admin, $steps);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if (!$this->open($admin)) {
            return $this->leave($admin, 0);
        }
        $steps = $this->steps($admin);
        $index = self::id($args);
        $step = $steps[$index] ?? null;
        if ($action !== 'v' || $step === null) {
            return $this->summary($admin, $steps);
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
     * Every field as it stands, a refusal of the panel's beside its own, «✏️» for each, and the save.
     *
     * @param list<Step> $steps
     */
    private function summary(Admin $admin, array $steps): View
    {
        $state = $admin->state($this->screen());
        $id = (int) ($state['id'] ?? 0);
        $draft = $this->draft($admin);
        $errors = Json::part($state, 'errors');
        $view = View::make($this->title($id, $draft), ...$this->path());

        $fields = [];
        $buttons = [];
        foreach ($steps as $index => $step) {
            if (!$step->shown($draft)) {
                continue;
            }
            $fields[] = $step->field;
            $view->line('• ' . Fmt::e($step->label) . ': ' . $step->display($draft[$step->key] ?? null));
            if (is_string($errors[$step->field] ?? null)) {
                $view->line('   ⚠️ ' . Fmt::e($errors[$step->field]));
            }
            // A switch's button sets the other value (pick 0: on, 1: off), never "the opposite of now": a tap twice is one.
            $on = (bool) ($draft[$step->key] ?? false);
            $buttons[] = $step->type === Step::TOGGLE
                ? Button::to($step->label . ': ' . ($on ? '✅' : '⛔️'), $this->screen(), 'pick', $index, $on ? 1 : 0)
                : Button::to('✏️ ' . $step->label, $this->screen(), 'ask', $index);
        }
        foreach ($errors as $field => $message) {
            if (!in_array($field, $fields, true) && is_string($message)) {
                $view->line('⚠️ ' . Fmt::e($message));
            }
        }
        foreach ($this->notes($admin, $id, $draft) as $note) {
            $view->note($note);
        }
        $view->grid(2, ...$buttons);
        [$screen, $action, $args] = $this->back($id);

        return $view->row(Button::to($id === 0 ? $this->createLabel() : '💾 ذخیره تغییرات', $this->screen(), 'save'), Button::to('✖️ انصراف', $screen, $action, ...$args));
    }

    /**
     * Ask field `$index`: a question to type at, or its choices as buttons.
     *
     * @param list<Step> $steps
     */
    private function ask(Admin $admin, array $steps, int $index): View
    {
        $step = $steps[$index];
        $state = $admin->state($this->screen());
        $walk = (bool) ($state['walk'] ?? false);
        $draft = $this->draft($admin);
        $value = $draft[$step->key] ?? null;

        $head = [];
        if ($walk) {
            $walked = array_keys(array_filter($steps, static fn(Step $one): bool => $one->walked && $one->shown($draft)));
            $place = array_search($index, $walked, true);
            $head[] = 'قدم ' . Fmt::number(($place === false ? 0 : $place) + 1) . ' از ' . Fmt::number(count($walked));
        }
        if ($step->hint !== '') {
            $head[] = Fmt::e($step->hint);
        }
        if (!$walk || !Step::blank($value)) {
            $head[] = 'الان: ' . $step->display($value);
        }
        [$screen, $action, $args] = $this->back((int) ($state['id'] ?? 0));
        $cancel = $walk ? Button::to('✖️ انصراف', $screen, $action, ...$args) : Button::to('✖️ لغو', $this->screen(), 'sum');
        $skip = $walk && $step->optional ? Button::to('⏭ رد شدن', $this->screen(), 'skip', $index) : null;

        if (in_array($step->type, [Step::CHOICE, Step::TOGGLE, Step::MULTI], true)) {
            $view = View::make('✍️ ' . $step->label)->lines(...$head);
            $chips = [];
            // The page first shown: the one the field was left on, else the one with its choice.
            $at = 0;
            if ($step->type === Step::TOGGLE) {
                $chips = [Button::to(((bool) $value ? '● ' : '') . '✅ بله', $this->screen(), 'pick', $index, 0), Button::to((!(bool) $value ? '● ' : '') . '⛔️ خیر', $this->screen(), 'pick', $index, 1)];
            } elseif ($step->type === Step::CHOICE) {
                foreach (array_keys($step->options()) as $n => $key) {
                    $chosen = $value === null ? $key === '' : (string) $key === (string) (is_scalar($value) ? $value : '');
                    $at = $chosen ? $n : $at;
                    $chips[] = Button::to(($chosen ? '● ' : '') . $step->options()[$key], $this->screen(), 'pick', $index, $n);
                }
            } else {
                $chosen = is_array($value) ? $value : null;
                if ($step->all !== '') {
                    $view->row(Button::to(($chosen === null ? '● ' : '') . $step->all, $this->screen(), 'all', $index));
                }
                foreach (array_keys($step->options()) as $n => $key) {
                    $chips[] = Button::to(($chosen !== null && in_array($key, $chosen, true) ? '✅ ' : '◻️ ') . $step->options()[$key], $this->screen(), 'mt', $index, $n);
                }
            }
            if ($chips === []) {
                $view->line('<i>چیزی برای انتخاب نیست.</i>');
            }
            $pages = Json::part($state, 'pages');
            $page = is_int($pages[$index] ?? null) ? $pages[$index] : intdiv($at, View::CHOICES_MOST) + 1;
            $view->paged(count($chips) > 4 ? 2 : 1, $chips, $page, fn(int $to): array => [$this->screen(), 'pg', [$index, $to]]);
            if ($step->type === Step::MULTI) {
                $view->row(Button::to('✔️ تایید', $this->screen(), 'done', $index));
            }

            return $view->row($skip, $cancel);
        }

        $prompt = new Prompt($step->label, $this->screen(), 'v', [$index], implode("\n", $head), $cancel);
        $choices = [];
        foreach ($step->quick as $n => [$label]) {
            $choices[] = Button::to($label, $this->screen(), 'q', $index, $n);
        }
        if ($skip !== null) {
            $choices[] = $skip;
        } elseif ($step->optional && !Step::blank($value)) {
            $choices[] = Button::to('🗑 خالی: ' . $step->none, $this->screen(), 'clr', $index);
        }

        return $admin->ask($prompt->choices(...$choices));
    }

    /**
     * After field `$index` is set: the next field on the way through, else the summary.
     *
     * @param list<Step> $steps
     */
    private function after(Admin $admin, array $steps, int $index): View
    {
        return (bool) ($admin->state($this->screen())['walk'] ?? false) ? $this->next($admin, $steps, $index) : $this->summary($admin, $steps);
    }

    /**
     * The first field after `$index` asked on the way through, shown as the draft stands; the summary past the last.
     *
     * @param list<Step> $steps
     */
    private function next(Admin $admin, array $steps, int $index): View
    {
        $draft = $this->draft($admin);
        foreach ($steps as $at => $step) {
            if ($at > $index && $step->walked && $step->shown($draft)) {
                return $this->ask($admin, $steps, $at);
            }
        }
        $admin->remember($this->screen(), ['walk' => false]);

        return $this->summary($admin, $steps);
    }

    /**
     * Save the draft: the row's screen, or the summary with the panel's refusals beside their fields.
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
            $labels = [];
            foreach ($steps as $step) {
                $labels[$step->field] ??= $step->label;
            }
            $admin->remember($this->screen(), ['errors' => $errors, 'walk' => false]);
            $admin->alert($refusal->lines($labels));

            return $this->summary($admin, $steps);
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

    /** Where the form's «✖️ انصراف» goes, for a form no longer open. */
    private function leave(Admin $admin, int $id): View
    {
        [$screen, $action, $args] = $this->back($id);

        return $admin->open($screen, $action, $args);
    }
}
