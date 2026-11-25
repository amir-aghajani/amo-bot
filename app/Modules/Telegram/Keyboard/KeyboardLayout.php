<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

use App\Modules\Telegram\Texts\PremiumText;

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
     * The same layout with each inline button that has no icon of its own given the premium emoji `$iconFor` names for
     * its label's leading emoji (EmojiMap::icon()): that emoji wrapped as a premium one, which the API client sends as the
     * button's icon (PlainWording) — plain again, in the label, while the bot may not send premium emoji. A label of its
     * emoji alone stays as it is: Telegram takes no button without words. A reply keyboard is left as it is: its tap
     * sends the label without its icon, and those words alone would be taken for the button wherever a customer typed
     * them, a step's answer too — there a button takes an icon of its own alone.
     *
     * @param \Closure(string): ?string $iconFor
     */
    public function premium(\Closure $iconFor): self
    {
        if (!$this->isInline()) {
            return $this;
        }

        $rows = [];
        foreach ($this->rows as $row) {
            $rows[] = array_map(static function (array $button) use ($iconFor): array {
                $id = self::iconableEmoji($button) === null ? null : $iconFor($button['label']);

                return $id === null ? $button : ['label' => PremiumText::name($button['label'], $id)->text] + $button;
            }, $row);
        }

        return new self($this->type, $rows);
    }

    /**
     * The leading emoji of each button premium() may give an icon, in reading order — none for a reply keyboard.
     *
     * @return list<string>
     */
    public function iconable(): array
    {
        if (!$this->isInline()) {
            return [];
        }
        $emojis = [];
        foreach ($this->buttons() as $button) {
            $leading = self::iconableEmoji($button);
            if ($leading !== null) {
                $emojis[] = $leading;
            }
        }

        return $emojis;
    }

    /**
     * The emoji a button's label starts with, when premium() may make it the button's icon: the button has none of its
     * own, and words follow (Telegram takes no button without them).
     *
     * @param array{label: string, icon: ?string} $button
     */
    private static function iconableEmoji(array $button): ?string
    {
        $leading = $button['icon'] === null ? PremiumText::leadingEmoji($button['label']) : null;

        return $leading === null || trim(substr($button['label'], strlen($leading))) === '' ? null : $leading;
    }

    /**
     * The `reply_markup` for a message. Inline buttons carry the action's callback (MainMenu::ACTIONS), or open the web
     * app `$opens` gives their action — the shop's mini app, which Telegram signs the customer in to from an inline
     * button alone; a reply keyboard's button is its label, whatever it stands for.
     *
     * @param array<string, string> $opens action => the address of the web app its inline button opens
     * @return array<string, mixed>
     */
    public function markup(array $opens = []): array
    {
        if ($this->isInline()) {
            $button = static fn(array $b): array => isset($opens[$b['action']])
                ? InlineKeyboard::webApp($b['label'], $opens[$b['action']], $b['style'], $b['icon'])
                : InlineKeyboard::callback($b['label'], MainMenu::callbackFor($b['action']), $b['style'], $b['icon']);
            $keyboard = InlineKeyboard::make();
            foreach ($this->rows as $row) {
                $keyboard->row(...array_map($button, array_reverse($row)));
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
