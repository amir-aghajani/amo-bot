<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

/**
 * A question a management screen asks (Admin::ask()): its words, a hint of what may be typed, and where the answer
 * goes — the screen's input() with this action and these arguments. It says the value now («مقدار فعلی», current()), an
 * example and the limits of what is taken (example(), limits()), and may offer leaving the field empty or putting it
 * back as it comes (clearable(), resettable()). The chat waits at it (state `adm.input`) until an answer, «✖️ انصراف»,
 * or any other button — a menu button of the reply keyboard too, unless `$anyText`; quick picks (`$choices`) may stand in
 * for typing, and a wizard's step offers its ways on (ways(): «⬅️ گام قبل» · «⏭ رد شدن») in a row of their own.
 *
 *   $admin->ask((new Prompt('حداقل مبلغ شارژ', 'bsw', 'e', [0], 'کمتر از این مبلغ پذیرفته نمی‌شود.'))
 *       ->current('۱۰٬۰۰۰ تومان')->example('۵۰۰۰۰')->limits('بیش از صفر')->clearable('bsw', 'x', 0));
 */
final class Prompt
{
    /**
     * @var list<Button>
     */
    public array $choices = [];

    /** The value now, as plain text; null to say none (a question with no value of its own). */
    public ?string $current = null;

    /** Whether the value now is shown to copy (in <code>); a secret's «ذخیره شده» is not. */
    public bool $copyable = true;

    /** What an answer looks like (plain text); '' for none. */
    public string $example = '';

    /** What an answer may be: its least, its most (plain text); '' for none. */
    public string $limits = '';

    /** «🗑 خالی کردن»'s button; null when the field may not be left empty. */
    public ?Button $clear = null;

    /** «↩️ پیش‌فرض»'s button; null when there is no value it comes with. */
    public ?Button $reset = null;

    /**
     * @var list<Button> A wizard step's ways on (ways()), in reading order
     */
    public array $ways = [];

    /**
     * @param list<int|string> $args
     */
    public function __construct(
        /** Plain text: the question — the field asked. */
        public readonly string $question,
        public readonly string $screen,
        public readonly string $action,
        public readonly array $args = [],
        /** HTML: what may be typed. */
        public readonly string $hint = '',
        /** Where «✖️ انصراف» goes (a cancel word drawn as the management's one: Admin::ask()): the screen's show() by default. */
        public readonly ?Button $cancel = null,
        /** Whether a picture or a file may be the answer. */
        public readonly bool $file = false,
        /**
         * Whether the answer may be any words, a menu button's label too (a button's or a bot text's new words): the
         * chat waits at Admin::INPUT_ANY, which the reply keyboard does not leave. Any other question is left by a menu
         * button — its label is never sent as an answer (to a customer's ticket, say).
         */
        public readonly bool $anyText = false,
    ) {}

    /** Buttons that answer it at a tap (each its own action). */
    public function choices(Button ...$choices): self
    {
        $this->choices = array_values($choices);

        return $this;
    }

    /** The value now («مقدار فعلی»), plain text — to copy unless `$copyable` is false (a secret's «ذخیره شده»). */
    public function current(?string $value, bool $copyable = true): self
    {
        $this->current = $value ?? '';
        $this->copyable = $copyable;

        return $this;
    }

    /** What an answer looks like, plain text: «۵۰۰۰۰». */
    public function example(string $example): self
    {
        $this->example = $example;

        return $this;
    }

    /** What an answer may be, plain text: «از ۱ تا ۸». */
    public function limits(string $limits): self
    {
        $this->limits = $limits;

        return $this;
    }

    /** «🗑 خالی کردن», to `$screen`'s `$action` with its arguments: the field left empty. */
    public function clearable(string $screen, string $action, int|string ...$args): self
    {
        $this->clear = Button::to(Words::CLEAR, $screen, $action, ...$args);

        return $this;
    }

    /** «↩️ پیش‌فرض», to `$screen`'s `$action` with its arguments: the field put back as it comes. */
    public function resettable(string $screen, string $action, int|string ...$args): self
    {
        $this->reset = Button::to(Words::RESET, $screen, $action, ...$args);

        return $this;
    }

    /**
     * A wizard step's ways on, a row of their own over the way out, in reading order — «⬅️ گام قبل», «⏭ رد شدن»
     * (Words::PREVIOUS_STEP, Words::SKIP); a null one (a way not offered at this step: no step before the first) left out.
     *
     *   ->ways($step > 1 ? Button::to(Words::PREVIOUS_STEP, 'gcw', 'back') : null, Button::to(Words::SKIP, 'gcw', 'skip'))
     */
    public function ways(?Button ...$ways): self
    {
        $this->ways = array_values(array_filter($ways, static fn(?Button $way): bool => $way !== null));

        return $this;
    }

    /**
     * @return array<string, mixed> As the chat keeps it while it waits.
     */
    public function keep(): array
    {
        return [
            'screen' => $this->screen,
            'action' => $this->action,
            'args' => array_map(strval(...), $this->args),
            'file' => $this->file,
            'any' => $this->anyText,
            'question' => $this->question,
            'hint' => $this->hint,
            'cancel' => $this->cancel?->markup(),
            'choices' => array_map(static fn(Button $choice): array => $choice->markup(), $this->choices),
            'current' => $this->current,
            'copyable' => $this->copyable,
            'example' => $this->example,
            'limits' => $this->limits,
            'clear' => $this->clear?->markup(),
            'reset' => $this->reset?->markup(),
            'ways' => array_map(static fn(Button $way): array => $way->markup(), $this->ways),
        ];
    }

    /**
     * The question as the chat kept it (keep()), to ask again as it was asked: a refused answer's. A question kept by an
     * older version lacks what it did not say; it is asked without it.
     *
     * @param array<mixed> $kept
     */
    public static function kept(array $kept): self
    {
        $args = is_array($kept['args'] ?? null) ? array_values(array_map(strval(...), array_filter($kept['args'], is_scalar(...)))) : [];
        $prompt = new self(
            is_string($kept['question'] ?? null) ? $kept['question'] : 'پاسخ را بفرستید',
            is_string($kept['screen'] ?? null) ? $kept['screen'] : Screens::HOME,
            is_string($kept['action'] ?? null) ? $kept['action'] : '',
            $args,
            is_string($kept['hint'] ?? null) ? $kept['hint'] : '',
            is_array($kept['cancel'] ?? null) ? Button::kept($kept['cancel']) : null,
            (bool) ($kept['file'] ?? false),
            (bool) ($kept['any'] ?? false),
        );
        $prompt->current = is_string($kept['current'] ?? null) ? $kept['current'] : null;
        $prompt->copyable = (bool) ($kept['copyable'] ?? true);
        $prompt->example = is_string($kept['example'] ?? null) ? $kept['example'] : '';
        $prompt->limits = is_string($kept['limits'] ?? null) ? $kept['limits'] : '';
        $prompt->clear = is_array($kept['clear'] ?? null) ? Button::kept($kept['clear']) : null;
        $prompt->reset = is_array($kept['reset'] ?? null) ? Button::kept($kept['reset']) : null;

        return $prompt->choices(...self::buttons($kept['choices'] ?? null))->ways(...self::buttons($kept['ways'] ?? null));
    }

    /**
     * Kept buttons (keep()'s markups) read back; what is no button left out.
     *
     * @return list<Button>
     */
    private static function buttons(mixed $markups): array
    {
        $buttons = [];
        foreach (is_array($markups) ? $markups : [] as $markup) {
            $button = is_array($markup) ? Button::kept($markup) : null;
            if ($button !== null) {
                $buttons[] = $button;
            }
        }

        return $buttons;
    }
}
