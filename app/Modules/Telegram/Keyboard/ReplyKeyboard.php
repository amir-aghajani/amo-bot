<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

/**
 * Builder for reply keyboards — the persistent buttons under the text field. A tap sends the
 * button's label as an ordinary message, so labels double as routes (Dispatcher::text()).
 *
 * Telegram lays a row out left to right in array order, whatever the language; for a Persian
 * screen list the buttons right-to-left reversed, so the first one a reader meets is last in the row.
 *
 *   ReplyKeyboard::make()->row('🔐 خرید', ReplyKeyboard::button('🔑 تست', 'primary'))->row('☎️ پشتیبانی')->build();
 */
final class ReplyKeyboard
{
    /** @var list<list<array<string, mixed>>> */
    private array $rows = [];

    public static function make(): self
    {
        return new self();
    }

    /**
     * @param string|null $style One of KeyboardLayout::STYLES ("primary" blue, "success" green, "danger" red); null = the client's default
     * @param string|null $icon A premium emoji's id, shown before the text (KeyboardLayout's button icon) — a tap still sends
     *                          the text alone; null = none
     * @return array<string, mixed>
     */
    public static function button(string $text, ?string $style = null, ?string $icon = null): array
    {
        $button = $style === null ? ['text' => $text] : ['text' => $text, 'style' => $style];

        return $icon === null ? $button : $button + ['icon_custom_emoji_id' => $icon];
    }

    /**
     * A button that sends the user's own phone number as a contact (after Telegram asks them to confirm).
     *
     * @return array<string, mixed>
     */
    public static function contactButton(string $text, ?string $style = null): array
    {
        return self::button($text, $style) + ['request_contact' => true];
    }

    /** @param string|array<string, mixed> ...$buttons A label, or a button() array */
    public function row(string|array ...$buttons): self
    {
        if ($buttons !== []) {
            $this->rows[] = array_map(static fn(string|array $b): array => is_string($b) ? self::button($b) : $b, array_values($buttons));
        }

        return $this;
    }

    /** @return array<string, mixed> The `reply_markup` value for sendMessage (not usable with editMessageText). */
    public function build(): array
    {
        return ['keyboard' => $this->rows, 'resize_keyboard' => true, 'is_persistent' => true];
    }

    /** @return array<string, mixed> Take the keyboard away (the bot switched off, a menu that is inline now). */
    public static function remove(): array
    {
        return ['remove_keyboard' => true];
    }
}
