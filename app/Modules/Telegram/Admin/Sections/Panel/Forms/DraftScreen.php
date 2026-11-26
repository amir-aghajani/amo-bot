<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Forms;

use App\Core\Security\Encrypter;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Support\Persian;

/**
 * A form the panel saves whole (DraftForm) as a management screen, drawn as a settings card is: a summary of the driver
 * picked and each field with its value (said once there; the buttons say only what they do), edited into a draft the
 * chat keeps — a switch set as its button says, a choice picked, the rest typed through a question that says the value
 * now (a secret is taken away once read, kept encrypted and never shown back) — then «💾 ذخیره» sends it in one
 * request, as the panel's card does. What the API refuses is said under the summary, field by field, the draft kept to
 * fix; «↩️ دور انداختن تغییرها» goes back to what is saved; the driver's and the fields' hints are folded away. A field
 * only the owner themselves changes (ownersOnly()) is shown to one of the main shop's admins with «🔒», never edited.
 *
 * Actions: `e` (i: edit field i; a switch's i and 1|0, the state it is set to), `c` (i, n: choice n), `pk` (i, n: a
 * found choice n), `x` (i: emptied / cleared), `k` (i: a secret kept as it is), `g` (i: a secret made up), `df` (i:
 * back to its default), `d` (the drivers), `dv` (n: driver n), `save`, `drop`.
 */
abstract class DraftScreen extends BaseScreen
{
    /** The most choices shown as buttons; a longer list (the time zones) is searched by typing. */
    private const BUTTONS_MOST = 12;

    /** The most found choices offered after a search. */
    private const FOUND_MOST = 10;

    public function __construct(protected readonly Encrypter $crypt) {}

    /** The screen's id, as PanelScreens registers it. */
    abstract protected function screen(): string;

    /** The card's title (plain text, with its emoji). */
    abstract protected function title(): string;

    /**
     * @return list<string> The sections it is in.
     */
    abstract protected function path(): array;

    /** Where «⬅️ بازگشت» goes. */
    abstract protected function parent(): string;

    /**
     * The form as the API now has it.
     *
     * @throws ApiRefusal
     */
    abstract protected function form(Admin $admin): DraftForm;

    /**
     * Send the draft (`$payload`, the request whole).
     *
     * @param array<string, mixed> $payload
     * @throws ApiRefusal 422 under each field refused
     */
    abstract protected function submit(Admin $admin, array $payload): void;

    /** What is said once it is saved. */
    protected function done(): string
    {
        return '✅ ذخیره شد.';
    }

    /** The button that sends the draft. */
    protected function submitLabel(): string
    {
        return Words::SAVE;
    }

    /** Whether the draft is sent even with nothing changed (a connection to try, not a setting to keep). */
    protected function alwaysSubmits(): bool
    {
        return false;
    }

    /** What the driver picked is called on the card («روش ارسال»). */
    protected function chooseLabel(): string
    {
        return 'روش';
    }

    /**
     * Lines over the fields (HTML): what the card is for, a warning.
     *
     * @return list<string>
     */
    protected function intro(Admin $admin, DraftForm $form, string $driver): array
    {
        return [];
    }

    /**
     * Lines and buttons under the fields (a test, its answer).
     *
     * @param array<string, string|bool> $values
     */
    protected function extra(View $view, Admin $admin, DraftForm $form, string $driver, array $values): void {}

    /**
     * Paragraphs folded away with the fields' hints (HTML): what the card has besides, for a site's developer; none by
     * default.
     *
     * @return list<string>
     */
    protected function notes(Admin $admin, DraftForm $form, string $driver): array
    {
        return [];
    }

    /**
     * Whether a field shows beside its own `when`, on a condition of the screen's (the owner's password while the Bot API
     * moves).
     *
     * @param array<string, mixed> $field
     * @param array<string, string|bool> $values
     */
    protected function visible(array $field, DraftForm $form, string $driver, array $values): bool
    {
        return true;
    }

    /**
     * The fields only the owner themselves changes, each with the API's refusal of anyone else (ActorRefusedException's
     * words): to one of the main shop's admins working with the owner's seat they show their value and say so, but are
     * never offered to edit — a save still sends them as they are. None by default.
     *
     * @return array<string, string> By field name
     */
    protected function ownersOnly(): array
    {
        return [];
    }

    /** The field a refusal's key names (`captcha.site_key` is `site_key`). */
    protected function errorField(string $key): string
    {
        return $key;
    }

    /**
     * An action of the screen's own (a test): the card again by default.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $values
     * @throws ApiRefusal
     */
    protected function action(Admin $admin, string $action, array $args, DraftForm $form, string $driver, array $values): View
    {
        return $this->card($admin, $form);
    }

    /**
     * An answer to a question of the screen's own.
     *
     * @param list<string> $args
     * @throws ApiRefusal
     */
    protected function typed(Admin $admin, string $action, array $args, Answer $answer, DraftForm $form): View
    {
        return $this->card($admin, $form);
    }

    public function show(Admin $admin, array $args): View
    {
        return $this->card($admin, $this->form($admin));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $form = $this->form($admin);
        [$driver, $values] = $this->draft($admin, $form);
        $fields = $form->fields($driver);
        $index = self::id($args);
        $field = $fields[$index] ?? null;

        switch ($action) {
            case 'save':
                return $this->save($admin, $form, $driver, $values);
            case 'drop':
                $this->forgetDraft($admin);

                return $this->card($admin, $form);
            case 'd':
                return $this->drivers($form, $driver);
            case 'dv':
                $key = array_keys($form->choices())[self::id($args)] ?? null;
                if ($key !== null) {
                    $this->keep($admin, $key, $form->start($key), []);
                }

                return $this->card($admin, $form);
        }
        if ($field === null || !in_array($action, ['e', 'c', 'pk', 'x', 'k', 'g', 'df'], true)) {
            return $this->action($admin, $action, $args, $form, $driver, $values);
        }
        // An older window's button, or one typed by hand, to a field only the owner changes.
        $refusal = $this->locked($admin, $field);
        if ($refusal !== null) {
            $admin->alert($refusal);

            return $this->card($admin, $form);
        }
        $name = DraftForm::name($field);
        switch ($action) {
            case 'e':
                return $this->edit($admin, $form, $driver, $values, $index, $field, $args[1] ?? null);
            case 'c':
            case 'pk':
                $options = array_keys(DraftForm::options($field));
                $picked = $options[self::id($args, 1)] ?? null;
                if ($picked !== null) {
                    $values[$name] = (string) $picked;
                }
                break;
            case 'x':
                if (DraftForm::isSecret($field)) {
                    $values[$name] = '';
                    $values['clear_' . $name] = ($field['transient'] ?? false) !== true;
                } elseif (($field['required'] ?? false) !== true) {
                    $values[$name] = '';
                }
                break;
            case 'k':
                $values[$name] = '';
                $values['clear_' . $name] = false;
                break;
            case 'df':
                $default = $field['default'] ?? null;
                if (is_scalar($default) && !is_bool($default)) {
                    $values[$name] = (string) $default;
                }
                break;
            case 'g':
                $length = (int) ($field['generate'] ?? 0);
                if ($length > 0) {
                    $values[$name] = DraftForm::seal(self::token($length), $this->crypt);
                    $values['clear_' . $name] = false;
                }
                break;
        }
        $this->keep($admin, $driver, $values, $this->without($admin, $name));

        return $this->card($admin, $form);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $form = $this->form($admin);
        if ($action !== 'e') {
            return $this->typed($admin, $action, $args, $answer, $form);
        }
        [$driver, $values] = $this->draft($admin, $form);
        $index = self::id($args);
        $field = $form->fields($driver)[$index] ?? null;
        if ($field === null) {
            return $this->card($admin, $form);
        }
        $refusal = $this->locked($admin, $field);
        if ($refusal !== null) {
            $admin->alert($refusal);

            return $this->card($admin, $form);
        }
        $name = DraftForm::name($field);
        $type = (string) ($field['type'] ?? 'text');
        if ($type === 'choice') {
            return $this->found($admin, $form, $driver, $values, $index, $field, $answer->text());
        }
        if (DraftForm::isSecret($field)) {
            $values[$name] = DraftForm::seal($answer->text(), $this->crypt);
            $values['clear_' . $name] = false;
        } elseif ($type === 'number') {
            $values[$name] = (string) ($answer->integer() ?? throw ApiRefusal::typed($name, Words::NOT_NUMBER));
        } else {
            $values[$name] = DraftForm::isLatin($field) ? $answer->latin() : $answer->text();
        }
        $this->keep($admin, $driver, $values, $this->without($admin, $name));

        return $this->card($admin, $form);
    }

    /**
     * The card: its intro and warnings, a summary of the driver picked and each field shown with its value — what the API
     * refused said under it, field by field —, whether the draft is unsaved, the buttons (the driver to pick, each switch
     * the state a tap sets, each other field «✏️ …»), «💾 ذخیره» while there is something to save, and the driver's and the
     * fields' hints folded away under the rest, the card's notes() with them.
     */
    protected function card(Admin $admin, DraftForm $form): View
    {
        [$driver, $values] = $this->draft($admin, $form);
        $state = $admin->state($this->screen());
        $errors = is_array($state['err'] ?? null) ? $state['err'] : [];
        $view = View::make($this->title(), ...$this->path());
        foreach ($this->intro($admin, $form, $driver) as $line) {
            $view->line($line);
        }
        foreach (is_array($state['top'] ?? null) ? $state['top'] : [] as $line) {
            $view->line('⚠️ ' . Fmt::e((string) $line));
        }

        $facts = [];
        $refused = [];
        $hints = [];
        $buttons = [];
        $locked = [];
        if ($form->choose !== null) {
            $facts[$this->chooseLabel()] = Fmt::e($form->choices()[$driver] ?? $driver);
            $about = $driver === DraftForm::NONE ? ($form->none['hint'] ?? '') : implode(' · ', array_filter([$form->drivers[$driver]['description'] ?? '', ...($form->drivers[$driver]['notes'] ?? [])]));
            if ($about !== '') {
                $hints[] = '<b>' . Fmt::e($form->choices()[$driver] ?? $driver) . '</b>: ' . Fmt::e($about);
            }
            if (isset($errors[$form->choose])) {
                $refused[] = '⚠️ ' . Fmt::e($this->chooseLabel()) . ': ' . Fmt::e((string) $errors[$form->choose]);
            }
            $buttons[] = Button::to('🔀 ' . $this->chooseLabel(), $this->screen(), 'd');
        }
        foreach ($form->shown($driver, $values) as $index => $field) {
            if (!$this->visible($field, $form, $driver, $values)) {
                continue;
            }
            $name = DraftForm::name($field);
            $label = DraftForm::label($field);
            $facts[$label] = $form->display($field, $driver, $values);
            if (is_string($field['hint'] ?? null) && $field['hint'] !== '') {
                $hints[] = '<b>' . Fmt::e($label) . '</b>: ' . Fmt::e($field['hint']);
            }
            if ($form->leftBehind($field, $driver, $values)) {
                $refused[] = '⚠️ ' . Fmt::e($label) . ': ' . Fmt::e(is_string($field['moved'] ?? null) ? $field['moved'] : 'دوباره بنویسید.');
            }
            if (isset($errors[$name])) {
                $refused[] = '⚠️ ' . Fmt::e($label) . ': ' . Fmt::e((string) $errors[$name]);
            }
            if ($this->locked($admin, $field) !== null) {
                $locked[] = '«' . Fmt::e($label) . '»';

                continue;
            }
            $buttons[] = ($field['type'] ?? '') === 'toggle'
                ? Button::to(Words::turn(($values[$name] ?? false) !== true, $label), $this->screen(), 'e', $index, ($values[$name] ?? false) === true ? 0 : 1)
                : Button::to(Words::edit($label), $this->screen(), 'e', $index);
        }
        $view->card($facts)->lines(...$refused);
        if ($locked !== []) {
            $view->line('🔒 ' . implode('، ', $locked) . ' را فقط خود مالک عوض می‌کند.');
        }
        $dirty = $form->dirty($driver, $values, $this->crypt);
        if ($dirty && !$this->alwaysSubmits()) {
            $view->line()->line('✏️ <b>تغییرها هنوز ذخیره نشده‌اند.</b>');
        }
        $view->tiles(...$buttons);
        $this->extra($view, $admin, $form, $driver, $values);
        if ($dirty || $this->alwaysSubmits()) {
            $view->row(Button::to($this->submitLabel(), $this->screen(), 'save')->success(), $dirty && !$this->alwaysSubmits() ? Button::to('↩️ دور انداختن تغییرها', $this->screen(), 'drop') : null);
        }

        return $view->help(implode("\n\n", [...$hints, ...$this->notes($admin, $form, $driver)]))->back($this->parent());
    }

    /**
     * The draft — driver and values — as the chat keeps it, else the form as saved.
     *
     * @return array{0: string, 1: array<string, string|bool>}
     */
    protected function draft(Admin $admin, DraftForm $form): array
    {
        $kept = $admin->state($this->screen())['draft'] ?? null;
        $driver = is_array($kept) && is_string($kept['d'] ?? null) ? $kept['d'] : null;
        if ($driver === null || !is_array($kept['v'] ?? null) || ($form->choose === null ? $driver !== '' : !$form->has($driver))) {
            return [$form->saved, $form->start($form->saved)];
        }
        $values = [];
        foreach ($kept['v'] as $name => $value) {
            if (is_string($value) || is_bool($value)) {
                $values[(string) $name] = $value;
            }
        }

        return [$driver, array_replace($form->start($driver), $values)];
    }

    /**
     * Keep the draft, and the refusals still standing.
     *
     * @param array<string, string|bool> $values
     * @param array<string, string> $errors
     */
    protected function keep(Admin $admin, string $driver, array $values, array $errors): void
    {
        $admin->remember($this->screen(), ['draft' => ['d' => $driver, 'v' => $values], 'err' => $errors, 'top' => []]);
    }

    /** The draft gone: the form as saved again. */
    protected function forgetDraft(Admin $admin): void
    {
        $admin->remember($this->screen(), ['draft' => null, 'err' => [], 'top' => []]);
    }

    /**
     * Send the draft; refused, each refusal is kept under its field (the rest over the card) and the draft stays.
     *
     * @param array<string, string|bool> $values
     */
    private function save(Admin $admin, DraftForm $form, string $driver, array $values): View
    {
        $visible = fn(array $field): bool => $this->visible($field, $form, $driver, $values);
        try {
            $this->submit($admin, $form->payload($driver, $values, $this->crypt, $visible));
        } catch (ApiRefusal $refusal) {
            $names = array_map(DraftForm::name(...), $form->fields($driver));
            if ($form->choose !== null) {
                $names[] = $form->choose;
            }
            $errors = [];
            $top = [];
            foreach ($refusal->errors as $key => $messages) {
                $field = $this->errorField((string) $key);
                if (in_array($field, $names, true)) {
                    $errors[$field] ??= (string) ($messages[0] ?? '');
                } else {
                    array_push($top, ...$messages);
                }
            }
            if ($errors === [] && $top === []) {
                $top[] = $refusal->getMessage();
            }
            $admin->remember($this->screen(), ['draft' => ['d' => $driver, 'v' => $values], 'err' => $errors, 'top' => array_values(array_unique($top))]);
            $admin->alert($errors !== [] ? 'ذخیره نشد؛ دلیلش زیر هر مورد آمده است.' : (string) ($top[0] ?? $refusal->getMessage()));

            return $this->card($admin, $form);
        }
        $this->forgetDraft($admin);
        $admin->notice($this->done());

        return $this->saved($admin);
    }

    /**
     * What is shown once the draft is sent: the card as the API now has it, by default.
     *
     * @throws ApiRefusal
     */
    protected function saved(Admin $admin): View
    {
        return $this->card($admin, $this->form($admin));
    }

    /**
     * A field asked: a switch set as its button said (`$set` 1 or 0; an older window's carries none and turns it over), a
     * choice among buttons — the one in force marked ✅ — (or searched by typing, a long one), anything else typed: its
     * question says the value now (a secret only whether one is kept), an example and its bounds, and offers the quick
     * ways a field has (emptied, put back as it comes, a secret made up, kept or cleared).
     *
     * @param array<string, string|bool> $values
     * @param array<string, mixed> $field
     */
    private function edit(Admin $admin, DraftForm $form, string $driver, array $values, int $index, array $field, ?string $set): View
    {
        $name = DraftForm::name($field);
        $type = (string) ($field['type'] ?? 'text');
        $label = DraftForm::label($field);
        if ($type === 'toggle') {
            $values[$name] = $set !== null ? $set === '1' : ($values[$name] ?? false) !== true;
            $this->keep($admin, $driver, $values, $this->without($admin, $name));

            return $this->card($admin, $form);
        }
        $options = DraftForm::options($field);
        $hint = is_string($field['hint'] ?? null) ? $field['hint'] : '';
        if ($type === 'choice' && count($options) <= self::BUTTONS_MOST) {
            $choices = [];
            foreach (array_keys($options) as $n => $value) {
                $choices[] = Button::to(((string) ($values[$name] ?? '') === (string) $value ? Words::PICKED : '') . $options[$value], $this->screen(), 'c', $index, $n);
            }

            return View::make($label, ...$this->within())
                ->help(Fmt::e($hint))
                ->tiles(...$choices)
                ->back($this->screen());
        }

        $secret = DraftForm::isSecret($field);
        $lines = $hint === '' ? [] : [Fmt::e($hint)];
        if ($type === 'choice') {
            $lines[] = 'چند حرف از نامش را بنویسید تا پیدایش کنم.';
        }
        if ($secret) {
            $lines[] = 'پیامتان بعد از خواندن پاک می‌شود و دیگر نشان داده نمی‌شود.';
        }
        $current = $values[$name] ?? '';
        $prompt = (new Prompt($label, $this->screen(), 'e', [$index], implode("\n", $lines), Button::to(Words::CANCEL, $this->screen())))
            ->current($secret || $type === 'choice' ? Fmt::plain($form->display($field, $driver, $values)) : (string) $current, !$secret)
            ->example(is_string($field['placeholder'] ?? null) ? $field['placeholder'] : '');
        if ($type === 'number' && is_int($field['min'] ?? null) && is_int($field['max'] ?? null)) {
            $prompt->limits('از ' . Fmt::number($field['min']) . ' تا ' . Fmt::number($field['max']) . (is_string($field['unit'] ?? null) ? ' ' . $field['unit'] : ''));
        }
        $quick = [];
        if ($secret) {
            $kept = $form->stored[$driver][$name] ?? null;
            if ((int) ($field['generate'] ?? 0) > 0) {
                $quick[] = Button::to('🎲 ساختن رمز تازه', $this->screen(), 'g', $index);
            }
            if ($current !== '' || ($values['clear_' . $name] ?? false) === true) {
                $quick[] = Button::to('↩️ همان قبلی بماند', $this->screen(), 'k', $index);
            }
            if (($field['required'] ?? false) !== true && ($field['transient'] ?? false) !== true && is_array($kept) && ($kept['set'] ?? false) === true && ($values['clear_' . $name] ?? false) !== true) {
                $prompt->clearable($this->screen(), 'x', $index);
            }
        } else {
            if (($field['required'] ?? false) !== true && $current !== '') {
                $prompt->clearable($this->screen(), 'x', $index);
            }
            $default = $field['default'] ?? null;
            if (is_scalar($default) && !is_bool($default) && (string) $default !== '' && (string) $default !== (string) $current) {
                $prompt->resettable($this->screen(), 'df', $index);
            }
        }

        return $admin->ask($prompt->choices(...$quick));
    }

    /**
     * A long choice found by what was typed: one match is picked, a few are offered — the one in force marked ✅ —, none
     * or too many asked again.
     *
     * @param array<string, string|bool> $values
     * @param array<string, mixed> $field
     */
    private function found(Admin $admin, DraftForm $form, string $driver, array $values, int $index, array $field, string $typed): View
    {
        $name = DraftForm::name($field);
        $needle = mb_strtolower(trim(Persian::latinDigits($typed)));
        $options = DraftForm::options($field);
        $found = [];
        foreach (array_keys($options) as $n => $value) {
            $value = (string) $value;
            if (mb_strtolower($value) === $needle) {
                $found = [$n => $value];
                break;
            }
            if ($needle !== '' && (str_contains(mb_strtolower($value), $needle) || str_contains(mb_strtolower($options[$value]), $needle))) {
                $found[$n] = $value;
            }
        }
        if ($found === []) {
            throw new ApiRefusal(422, "چیزی با این نام پیدا نشد؛ جور دیگری بنویسید، مثل \u{2068}Tehran\u{2069}.");
        }
        if (count($found) === 1) {
            $values[$name] = (string) reset($found);
            $this->keep($admin, $driver, $values, $this->without($admin, $name));

            return $this->card($admin, $form);
        }
        if (count($found) > self::FOUND_MOST) {
            throw new ApiRefusal(422, 'موارد زیادی پیدا شد (' . Fmt::number(count($found)) . ')؛ دقیق‌تر بنویسید.');
        }
        $buttons = [];
        foreach ($found as $n => $value) {
            $buttons[] = Button::to(((string) ($values[$name] ?? '') === $value ? Words::PICKED : '') . $options[$value], $this->screen(), 'pk', $index, $n);
        }

        return View::make(DraftForm::label($field), ...$this->within())
            ->line('کدام یک؟')
            ->grid(1, ...$buttons)
            ->back($this->screen());
    }

    /** The drivers to pick among, each with what it is — the one in force marked ✅. */
    private function drivers(DraftForm $form, string $driver): View
    {
        $view = View::make($this->chooseLabel(), ...$this->within());
        $buttons = [];
        foreach (array_keys($form->choices()) as $n => $key) {
            $about = $key === DraftForm::NONE ? ($form->none['hint'] ?? '') : ($form->drivers[$key]['description'] ?? '');
            $view->line("\u{200F}• <b>" . Fmt::e($form->choices()[$key]) . '</b>' . ($about !== '' ? ': ' . Fmt::e($about) : ''));
            $buttons[] = Button::to(($key === $driver ? Words::PICKED : '') . $form->choices()[$key], $this->screen(), 'dv', $n);
        }

        return $view->tiles(...$buttons)->back($this->screen());
    }

    /**
     * Where what the card opens itself is (a choice's buttons, the drivers): the card's path and its own name.
     *
     * @return list<string>
     */
    private function within(): array
    {
        return [...$this->path(), Fmt::bare($this->title())];
    }

    /**
     * The API's refusal of `$field` to this seat — one of the main shop's admins, and a field only the owner changes
     * (ownersOnly()) —; null to the owner themselves and for every other field.
     *
     * @param array<string, mixed> $field
     */
    private function locked(Admin $admin, array $field): ?string
    {
        return $admin->seat->principal->isTheOwner() ? null : ($this->ownersOnly()[DraftForm::name($field)] ?? null);
    }

    /**
     * The refusals kept, but the one of `$name` (fixed now).
     *
     * @return array<string, string>
     */
    private function without(Admin $admin, string $name): array
    {
        $errors = $admin->state($this->screen())['err'] ?? [];
        $errors = is_array($errors) ? array_map(strval(...), $errors) : [];
        unset($errors[$name]);

        return $errors;
    }

    /** A random token of `$length` characters of Latin letters and digits (the panel's randomToken()). */
    private static function token(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $token;
    }
}
