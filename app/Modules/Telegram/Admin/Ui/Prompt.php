<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

/**
 * A question a management screen asks (Admin::ask()): its words, a hint of what may be typed, and where the answer
 * goes — the screen's input() with this action and these arguments. The chat waits at it (state `adm.input`) until an
 * answer, «✖️ لغو», or any other button — a menu button of the reply keyboard too, unless `$anyText`; quick picks
 * (`$choices`) may stand in for typing.
 */
final class Prompt
{
    /**
     * @var list<Button>
     */
    public array $choices = [];

    /**
     * @param list<int|string> $args
     */
    public function __construct(
        /** Plain text: the question. */
        public readonly string $question,
        public readonly string $screen,
        public readonly string $action,
        public readonly array $args = [],
        /** HTML: what may be typed, the current value. */
        public readonly string $hint = '',
        /** Where «✖️ لغو» goes: the screen's show() by default. */
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
        ];
    }

    /**
     * The question as the chat kept it (keep()), to ask again as it was asked: a refused answer's.
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
        $choices = [];
        foreach (is_array($kept['choices'] ?? null) ? $kept['choices'] : [] as $markup) {
            $choice = is_array($markup) ? Button::kept($markup) : null;
            if ($choice !== null) {
                $choices[] = $choice;
            }
        }

        return $prompt->choices(...$choices);
    }
}
