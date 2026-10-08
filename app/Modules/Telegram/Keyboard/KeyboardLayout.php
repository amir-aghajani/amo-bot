<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

/**
 * A keyboard as the admin laid it out: rows of buttons, each an action of the bot (MainMenu::ACTIONS)
 * under a label the admin chose, optionally in one of Telegram's button styles and with a premium emoji
 * as its icon (Telegram's `icon_custom_emoji_id`, drawn before the label — only while the bot may use
 * premium emoji: its owner has Telegram Premium; BotApi sends the keyboard without icons where Telegram
 * turns them down). Rendered either as a reply keyboard (persistent, under the text field) or as inline
 * buttons under the message.
 *
 * Rows are stored in reading order (first button = rightmost on a Persian screen) and reversed when
 * rendered, since Telegram lays rows out left to right.
 */
final class KeyboardLayout
{
    public const TYPE_REPLY = 'reply';
    public const TYPE_INLINE = 'inline';
    public const TYPES = [self::TYPE_REPLY, self::TYPE_INLINE];

    /** Telegram's button styles (Bot API `style` field): blue, green, red; null = the client's default look. */
    public const STYLES = ['primary', 'success', 'danger'];

    /** A button's icon: a premium emoji's id, as Telegram numbers them (digits, a 64-bit number). */
    public const ICON_PATTERN = '/^[0-9]{1,20}$/';

    /**
     * @param list<list<array{action: string, label: string, style: string|null, icon: string|null}>> $rows
     */
    public function __construct(
        public readonly string $type,
        public readonly array $rows,
    ) {}

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
