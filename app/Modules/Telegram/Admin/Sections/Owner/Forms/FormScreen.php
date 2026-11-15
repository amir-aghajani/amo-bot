<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Forms;

use App\Core\Security\Encrypter;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * A draft of a described form (DescribedForm) as a management screen — a server's connection, a payment method —: a new
 * one walks its fields one by one (the basic ones, «⏭ رد شدن» for those that may stay as they are), then — as an edit
 * opens at once — a card of every field shown with its value, «✏️» on each, the advanced ones behind «⚙️ تنظیمات
 * پیشرفته», and one save: the whole form in one API call, as the panel saves it. A form the API refuses (422) is said
 * under each field it names, and the draft stays.
 *
 * State (the screen's): `key` (the row edited; 0 for a new one), `driver`, `draft`, `initial`, `stored` (the row's
 * values as the API presents them; null for a new one), `walk` (the field being asked, while walking), `adv`, `errors`,
 * `probe` (a screen's own answer to show: a connection test).
 *
 * Actions: `e` (i: edit field i), `c` (i, n: field i's choice n), `t` (i, 0/1: switch field i), `p` (i, n: field i's
 * preset n typed), `s` (i: skip field i in the walk), `x` (i: clear field i — a kept secret emptied), `k` (i: a
 * secret's kept one again), `sum` (the walk ended), `adv` (1/0: the advanced fields shown or hidden), `save`, `quit` (the
 * draft dropped).
 */
abstract class FormScreen extends BaseScreen
{
    /** The longest choice label laid two a row: more is cut on a phone's half row. */
    private const HALF_ROW = 20;

    public function __construct(protected readonly Encrypter $crypt) {}

    /** The screen's id, as Screens registers it. */
    abstract protected function screen(): string;

    /**
     * The draft's form — its driver's described fields, with the screen's own —; null when it has none any more.
     *
     * @param array<string, mixed> $state
     */
    abstract protected function form(Admin $admin, array $state): ?DescribedForm;

    /**
     * The card's title (plain text, with its emoji).
     *
     * @param array<string, mixed> $state
     */
    abstract protected function title(array $state): string;

    /**
     * @param array<string, mixed> $state
     * @return list<string> The sections it is in.
     */
    abstract protected function path(array $state): array;

    /**
     * Where the form is left for: the row's page (an edit), the picker (a new one).
     *
     * @param array<string, mixed> $state
     */
    abstract protected function leave(Admin $admin, array $state): View;

    /**
     * Save the draft — one API call — and what to show after; a refusal propagates (FormScreen says a 422 under its
     * fields).
     *
     * @param array<string, mixed> $state
     * @throws ApiRefusal
     */
    abstract protected function save(Admin $admin, array $state, DescribedForm $form): View;

    /**
     * The save button's words.
     *
     * @param array<string, mixed> $state
     */
    protected function saveLabel(array $state): string
    {
        return self::isNew($state) ? '✔️ ساختن' : '💾 ذخیره تغییرات';
    }

    /**
     * Whether the draft may be saved as it stands (a grant that reaches no service is not): the save button with it.
     *
     * @param array<string, mixed> $state
     */
    protected function ready(array $state): bool
    {
        return true;
    }

    /**
     * Lines over the fields (the driver's notes).
     *
     * @param array<string, mixed> $state
     */
    protected function intro(View $view, Admin $admin, array $state): void {}

    /**
     * Lines and buttons under the fields (a connection test and its answer).
     *
     * @param array<string, mixed> $state
     */
    protected function extra(View $view, Admin $admin, array $state): void {}

    /**
     * An action of the screen's own (starting a draft, a test): the card again by default — the screen it is left for
     * while no draft is open.
     *
     * @param list<string> $args
     * @param array<string, mixed> $state
     * @throws ApiRefusal
     */
    protected function action(Admin $admin, string $action, array $args, array $state, DescribedForm $form): View
    {
        return isset($state['draft']) ? $this->card($admin, $state, $form) : $this->leave($admin, $state);
    }

    /**
     * Start a draft from `$state` (`key`, `driver`, `stored` and the screen's own): walking a new one's fields, or its card.
     *
     * @param array<string, mixed> $state
     */
    protected function begin(Admin $admin, array $state, DescribedForm $form, bool $walk): View
    {
        $stored = is_array($state['stored'] ?? null) ? $state['stored'] : null;
        $state['draft'] = $form->draft($stored);
        $state['initial'] = $state['draft'];
        $state['walk'] = $walk ? self::next($form, $state['draft'], -1) : null;
        $state += ['adv' => false, 'errors' => [], 'probe' => null];
        $admin->forget($this->screen());
        $admin->remember($this->screen(), $state);

        return $state['walk'] === null ? $this->card($admin, $state, $form) : $this->edit($admin, $state, $form, (int) $state['walk']);
    }

    public function show(Admin $admin, array $args): View
    {
        $state = $admin->state($this->screen());
        $form = isset($state['draft']) ? $this->form($admin, $state) : null;

        return $form === null ? $this->leave($admin, $state) : $this->card($admin, $state, $form);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $state = $admin->state($this->screen());
        $form = isset($state['draft']) ? $this->form($admin, $state) : null;
        if ($form === null) {
            return $this->action($admin, $action, $args, $state, new DescribedForm([], $this->crypt));
        }
        $draft = self::draftOf($state);
        $index = self::id($args);
        $field = $form->shown($draft)[$index] ?? null;
        $name = $field === null ? '' : DescribedForm::name($field);

        switch ($action) {
            case 'e':
                return $field === null ? $this->card($admin, $state, $form) : $this->edit($admin, $state, $form, $index);
            case 'c':
                $value = $field === null ? null : DescribedForm::values($field)[self::id($args, 1)] ?? null;

                return $value === null ? $this->card($admin, $state, $form) : $this->changed($admin, $state, $form, $index, [$name => $value]);
            case 't':
                return $field === null || ($field['type'] ?? null) !== 'toggle' ? $this->card($admin, $state, $form) : $this->changed($admin, $state, $form, $index, [$name => self::id($args, 1) === 1]);
            case 'p':
                $preset = $field === null ? null : ((array) ($field['presets'] ?? []))[self::id($args, 1)] ?? null;

                return is_array($preset) ? $this->changed($admin, $state, $form, $index, [$name => (string) ($preset['value'] ?? '')]) : $this->card($admin, $state, $form);
            case 's':
                return $this->changed($admin, $state, $form, $index, []);
            case 'x':
                if ($field === null) {
                    break;
                }

                return $this->changed($admin, $state, $form, $index, DescribedForm::isSecret($field) ? [$name => '', 'clear_' . $name => true] : [$name => '']);
            case 'k':
                return $field === null ? $this->card($admin, $state, $form) : $this->changed($admin, $state, $form, $index, [$name => '', 'clear_' . $name => false]);
            case 'sum':
                $state['walk'] = null;
                $admin->remember($this->screen(), ['walk' => null]);

                return $this->card($admin, $state, $form);
            case 'adv':
                $state['adv'] = self::id($args) === 1;
                $admin->remember($this->screen(), ['adv' => $state['adv']]);

                return $this->card($admin, $state, $form);
            case 'save':
                try {
                    return $this->save($admin, $state, $form);
                } catch (ApiRefusal $refusal) {
                    return $this->refused($admin, $state, $form, $refusal);
                }
            case 'quit':
                $admin->forget($this->screen());

                return $this->leave($admin, $state);
            default:
                return $this->action($admin, $action, $args, $state, $form);
        }

        return $this->card($admin, $state, $form);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $state = $admin->state($this->screen());
        $form = isset($state['draft']) ? $this->form($admin, $state) : null;
        if ($form === null) {
            return $this->leave($admin, $state);
        }
        $draft = self::draftOf($state);
        $index = self::id($args);
        $field = $form->shown($draft)[$index] ?? null;
        if ($action !== 'v' || $field === null) {
            return $this->card($admin, $state, $form);
        }

        return $this->changed($admin, $state, $form, $index, $form->read($field, $answer, $draft));
    }

    /**
     * The draft as it stands: every field shown with its value — a refusal under the field it names —, a button each.
     *
     * @param array<string, mixed> $state
     */
    protected function card(Admin $admin, array $state, DescribedForm $form): View
    {
        $view = View::make($this->title($state), ...$this->path($state));
        $this->intro($view, $admin, $state);
        $draft = self::draftOf($state);
        $stored = is_array($state['stored'] ?? null) ? $state['stored'] : null;
        $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];
        $shown = $form->shown($draft);
        $names = array_map(DescribedForm::name(...), $shown);
        foreach ($errors as $name => $messages) {
            if (!in_array((string) $name, $names, true)) {
                foreach ((array) $messages as $message) {
                    $view->line('⚠️ ' . Fmt::e(is_scalar($message) ? (string) $message : ''));
                }
            }
        }

        $advanced = array_filter($shown, static fn(array $field): bool => ($field['advanced'] ?? false) === true);
        $open = ($state['adv'] ?? false) === true;
        $basic = [];
        $more = [];
        foreach ($shown as $index => $field) {
            $deep = isset($advanced[$index]);
            if ($deep && !$open) {
                continue;
            }
            if ($deep && $more === []) {
                $view->line()->line('<b>⚙️ تنظیمات پیشرفته</b>');
            }
            $name = DescribedForm::name($field);
            $view->line('• ' . Fmt::e(DescribedForm::label($field)) . ': ' . $form->display($field, $draft, $stored));
            if ($form->moved($field, $draft, $stored) && is_string($field['moved'] ?? null)) {
                $view->line('   ⚠️ ' . Fmt::e($field['moved']));
            }
            foreach ((array) ($errors[$name] ?? []) as $message) {
                $view->line('   ⚠️ ' . Fmt::e(is_scalar($message) ? (string) $message : ''));
            }
            // A switch's button carries the value it sets: a stale card or a second tap never flips it back.
            $button = ($field['type'] ?? null) === 'toggle'
                ? Button::to(DescribedForm::label($field) . ': ' . $form->brief($field, $draft, $stored), $this->screen(), 't', $index, ($draft[$name] ?? false) === true ? 0 : 1)
                : Button::to(DescribedForm::label($field) . ': ' . $form->brief($field, $draft, $stored), $this->screen(), 'e', $index);
            if ($deep) {
                $more[] = $button;
            } else {
                $basic[] = $button;
            }
        }
        if (!self::isNew($state) && $draft !== self::draftOf(['draft' => $state['initial'] ?? []])) {
            $view->note('تغییرها هنوز ذخیره نشده‌اند.');
        }

        $view->grid(1, ...$basic);
        if ($advanced !== []) {
            $view->row(Button::to(($open ? '▴ ' : '▾ ') . '⚙️ تنظیمات پیشرفته' . Fmt::badge(count($advanced)), $this->screen(), 'adv', $open ? 0 : 1));
        }
        $view->grid(1, ...$more);
        $this->extra($view, $admin, $state);
        $view->row(...array_values(array_filter([
            $this->ready($state) ? Button::to($this->saveLabel($state), $this->screen(), 'save') : null,
            Button::to('✖️ انصراف', $this->screen(), 'quit'),
        ])));

        return $view->back($this->screen(), 'quit');
    }

    /**
     * A refusal of the draft: a 422's messages under the fields they name, said in a popup too; any other propagates.
     *
     * @param array<string, mixed> $state
     * @throws ApiRefusal
     */
    protected function refused(Admin $admin, array $state, DescribedForm $form, ApiRefusal $refusal): View
    {
        if ($refusal->status !== 422 || $refusal->errors === []) {
            throw $refusal;
        }
        $state['errors'] = $refusal->errors;
        $state['walk'] = null;
        $admin->remember($this->screen(), ['errors' => $refusal->errors, 'walk' => null]);
        $admin->alert($refusal->lines($form->labels()));

        return $this->card($admin, $state, $form);
    }

    /**
     * @param array<string, mixed> $state
     */
    protected static function isNew(array $state): bool
    {
        return (int) ($state['key'] ?? 0) === 0;
    }

    /**
     * The draft of `$state`.
     *
     * @param array<string, mixed> $state
     * @return array<string, string|bool>
     */
    protected static function draftOf(array $state): array
    {
        $draft = [];
        foreach ((array) ($state['draft'] ?? []) as $name => $value) {
            $draft[(string) $name] = is_bool($value) ? $value : (is_scalar($value) ? (string) $value : '');
        }

        return $draft;
    }

    /**
     * A field changed (or passed by, in a walk): kept in the draft — any refusal of it and the last answer forgotten —,
     * then the walk's next field, or the card.
     *
     * @param array<string, mixed> $state
     * @param array<string, string|bool> $changes
     */
    private function changed(Admin $admin, array $state, DescribedForm $form, int $index, array $changes): View
    {
        $state['draft'] = array_replace(self::draftOf($state), $changes);
        $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];
        foreach (array_keys($changes) as $name) {
            unset($errors[$name]);
        }
        $state['errors'] = $errors;
        $state['probe'] = null;
        if (($state['walk'] ?? null) !== null) {
            $state['walk'] = self::next($form, $state['draft'], $index);
        }
        $admin->remember($this->screen(), ['draft' => $state['draft'], 'errors' => $errors, 'probe' => null, 'walk' => $state['walk'] ?? null]);

        return ($state['walk'] ?? null) !== null ? $this->edit($admin, $state, $form, (int) $state['walk']) : $this->card($admin, $state, $form);
    }

    /**
     * Field `$index` asked: a switch (the card's own button sets it at a tap) or a choice among buttons, the rest typed —
     * with what it holds now, and in a walk the way past it.
     *
     * @param array<string, mixed> $state
     */
    private function edit(Admin $admin, array $state, DescribedForm $form, int $index): View
    {
        $draft = self::draftOf($state);
        $stored = is_array($state['stored'] ?? null) ? $state['stored'] : null;
        $field = $form->shown($draft)[$index] ?? null;
        if ($field === null) {
            return $this->card($admin, $state, $form);
        }
        $name = DescribedForm::name($field);
        $walking = ($state['walk'] ?? null) !== null;
        $type = (string) ($field['type'] ?? 'text');

        $hint = [];
        if (is_string($field['hint'] ?? null) && $field['hint'] !== '') {
            $hint[] = Fmt::e($field['hint']);
        }
        if ($form->moved($field, $draft, $stored) && is_string($field['moved'] ?? null)) {
            $hint[] = '⚠️ ' . Fmt::e($field['moved']);
        }

        if ($type === 'toggle' || $type === 'choice') {
            $view = View::make(DescribedForm::label($field), ...[...$this->path($state), $this->title($state)]);
            $view->lines(...$hint);
            $choices = $type === 'toggle'
                ? [Button::to('✅ بله', $this->screen(), 't', $index, 1), Button::to('⛔️ خیر', $this->screen(), 't', $index, 0)]
                : array_map(fn(int $n, string $value): Button => Button::to((($draft[$name] ?? '') === $value ? '● ' : '') . (DescribedForm::option($field, $value) ?? $value), $this->screen(), 'c', $index, $n), array_keys(DescribedForm::values($field)), DescribedForm::values($field));
            if (!$walking) {
                $view->line('الان: ' . $form->display($field, $draft, $stored));
            }
            // Two a row only while each label fits half a phone's row: a longer one would be cut where choices differ
            // («تتر روی شبکه ترون (TRC20)»).
            $short = max([0, ...array_map(static fn(Button $choice): int => mb_strlen($choice->label), $choices)]) <= self::HALF_ROW;
            $view->grid($short && (count($choices) > 4 || count($choices) === 2) ? 2 : 1, ...$choices);
            if ($walking) {
                // The walk's way out is its typed steps' «✖️ انصراف»: a «⬅️ بازگشت» here would read as a step back, yet drop
                // the draft.
                return $view->row(Button::to('⏭ رد شدن', $this->screen(), 's', $index), Button::to('📋 خلاصه', $this->screen(), 'sum'))
                    ->row(Button::to('✖️ انصراف', $this->screen(), 'quit'));
            }

            return $view->back($this->screen());
        }

        $kept = is_array($stored[$name] ?? null) && (($stored[$name]['set'] ?? false) === true);
        if (DescribedForm::isSecret($field)) {
            if ($kept) {
                $hint[] = 'الان: ' . $form->display($field, $draft, $stored) . '؛ برای عوض کردنش، تازه‌اش را بفرستید.';
            }
            $hint[] = 'پیامتان بعد از خواندن پاک می‌شود و دیگر نشان داده نمی‌شود.';
        } elseif (($draft[$name] ?? '') !== '') {
            $hint[] = 'الان: ' . ($type === 'textarea' ? '<blockquote>' . Fmt::e((string) $draft[$name]) . '</blockquote>' : $form->display($field, $draft, $stored));
        }
        if ($type === 'premium_emoji') {
            $hint[] = 'یک ایموجی پرمیوم بفرستید؛ ایموجی معمولی آن جای اول نام می‌نشیند.';
        } elseif (is_string($field['placeholder'] ?? null) && $field['placeholder'] !== '') {
            $hint[] = 'مثلا: ' . Fmt::ltr($field['placeholder']);
        }
        if ($type === 'list') {
            $hint[] = 'عددها را با فاصله یا «،» جدا کنید.';
        }
        if ($type === 'number' && is_numeric($field['min'] ?? null) && is_numeric($field['max'] ?? null)) {
            $hint[] = 'بین ' . Fmt::number($field['min']) . ' تا ' . Fmt::number($field['max']) . '.';
        }

        $prompt = new Prompt(
            DescribedForm::label($field),
            $this->screen(),
            'v',
            [$index],
            implode("\n", $hint),
            $walking ? Button::to('✖️ انصراف', $this->screen(), 'quit') : Button::to('✖️ لغو', $this->screen(), Nav::SHOW),
            file: $type === 'premium_emoji',
        );
        $choices = [];
        foreach ((array) ($field['presets'] ?? []) as $n => $preset) {
            if (is_array($preset)) {
                $choices[] = Button::to((string) ($preset['label'] ?? ''), $this->screen(), 'p', $index, (int) $n);
            }
        }
        // A required field may be passed by too while it holds a value already (a default: a method's label).
        if ($walking && (DescribedForm::optional($field) || ($draft[$name] ?? '') !== '')) {
            $choices[] = Button::to('⏭ رد شدن', $this->screen(), 's', $index);
        }
        if (DescribedForm::isSecret($field)) {
            if ($kept && (($draft[$name] ?? '') !== '' || ($draft['clear_' . $name] ?? false) === true)) {
                $choices[] = Button::to('↩️ همان قبلی بماند', $this->screen(), 'k', $index);
            }
            if ($kept && ($field['required'] ?? false) !== true && ($draft['clear_' . $name] ?? false) !== true) {
                $choices[] = Button::to('🗑 پاک کردن', $this->screen(), 'x', $index);
            }
            if (!$kept && ($draft[$name] ?? '') !== '') {
                $choices[] = Button::to('🗑 خالی کردن', $this->screen(), 'k', $index);
            }
        } elseif (!$walking && ($draft[$name] ?? '') !== '' && ($field['required'] ?? false) !== true) {
            $choices[] = Button::to('🗑 خالی کردن', $this->screen(), 'x', $index);
        }
        if ($walking) {
            $choices[] = Button::to('📋 خلاصه', $this->screen(), 'sum');
        }

        return $admin->ask($prompt->choices(...$choices));
    }

    /**
     * The walk's next field after `$after`: a basic one shown, that a walk asks.
     *
     * @param array<string, string|bool> $draft
     */
    private static function next(DescribedForm $form, array $draft, int $after): ?int
    {
        foreach ($form->shown($draft) as $index => $field) {
            if ($index > $after && ($field['advanced'] ?? false) !== true && ($field['walk'] ?? true) !== false) {
                return $index;
            }
        }

        return null;
    }
}
