<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

/**
 * A keyboard as the admin laid it out: rows of buttons, each an action (MainMenu::ACTIONS) with the admin's label, an
 * optional Telegram button style and a premium emoji icon. Rendered as a reply keyboard or as inline buttons. Rows are
 * stored in reading order (the first button is the rightmost on a Persian screen) and reversed when rendered, since
 * Telegram lays a row out left to right.
 */
final class KeyboardLayout
{
    public const TYPE_REPLY = 'reply';
    public const TYPE_INLINE = 'inline';
    public const TYPES = [self::TYPE_REPLY, self::TYPE_INLINE];

    /** Telegram's button styles (Bot API `style` field): blue, green, red; null = the client's default look. */
    public const STYLES = ['primary', 'success', 'danger'];

    /** How many buttons a row of the bot's plan and location lists holds at most (a plan's, a server's `button_row`). */
    public const ROW_MOST = 3;

    /** The refusal of a plan's or a server's button colour other than STYLES and the client's default. */
    public const STYLE_REFUSED = 'رنگ دکمه را از میان آبی، سبز و قرمز انتخاب کنید، یا پیش‌فرض تلگرام.';

    /** The refusal of a plan's or a server's `button_row` other than 1 to ROW_MOST. */
    public const ROW_REFUSED = 'جای دکمه یک ردیف کامل، دو یا سه دکمه در ردیف است.';

    /** A button's icon: a premium emoji's id, as Telegram numbers them (digits, a 64-bit number). */
    public const ICON_PATTERN = '/^[0-9]{1,20}$/';

    /**
     * @param list<list<array{action: string, label: string, style: string|null, icon: string|null}>> $rows
     */
    public function __construct(
        public readonly string $type,
        public readonly array $rows,
    ) {}

    /** `$style` while it is one of STYLES — a plan's or a server's kept one —, else null: the client's own look. */
    public static function style(?string $style): ?string
    {
        return in_array($style, self::STYLES, true) ? $style : null;
    }

    public function isInline(): bool
    {
        return $this->type === self::TYPE_INLINE;
    }

    /** @return list<array{action: string, label: string, style: string|null, icon: string|null}> */
    public function buttons(): array
    {
        return array_merge(...($this->rows ?: [[]]));
    }

    /** The same layout without the buttons of these actions; a row left empty goes too. */
    public function without(string ...$actions): self
    {
        $rows = [];
        foreach ($this->rows as $row) {
            $kept = array_values(array_filter($row, static fn(array $button): bool => !in_array($button['action'], $actions, true)));
            if ($kept !== []) {
                $rows[] = $kept;
            }
        }

        return new self($this->type, $rows);
    }

    /** @return list<string> Every label, for routing the messages a reply keyboard sends. */
    public function labels(): array
    {
        return array_values(array_map(static fn(array $b): string => $b['label'], $this->buttons()));
    }

    /** The action key behind a label (a tapped reply-keyboard button), null for any other text. */
    public function actionFor(?string $text): ?string
    {
        $text = trim((string) $text);
        foreach ($this->buttons() as $button) {
            if ($button['label'] === $text) {
                return $button['action'];
            }
        }

        return null;
    }

    /**
     * The `reply_markup` for a message. Inline buttons carry the action's callback (MainMenu::ACTIONS).
     *
     * @return array<string, mixed>
     */
    public function markup(): array
    {
        if ($this->isInline()) {
            $keyboard = InlineKeyboard::make();
            foreach ($this->rows as $row) {
                $keyboard->row(...array_map(static fn(array $b): array => InlineKeyboard::callback($b['label'], MainMenu::callbackFor($b['action']), $b['style'], $b['icon']), array_reverse($row)));
            }

            return $keyboard->build();
        }

        $keyboard = ReplyKeyboard::make();
        foreach ($this->rows as $row) {
            $keyboard->row(...array_map(static fn(array $b): array => ReplyKeyboard::button($b['label'], $b['style'], $b['icon']), array_reverse($row)));
        }

        return $keyboard->build();
    }

    /** @return array{type: string, rows: list<list<array{action: string, label: string, style: string|null, icon: string|null}>>} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'rows' => $this->rows];
    }
}
