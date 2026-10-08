<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

/**
 * Fluent builder for inline keyboards (buttons attached to a message).
 *
 *   InlineKeyboard::make()
 *       ->row(InlineKeyboard::callback('Plans', 'menu:plans'), InlineKeyboard::callback('Wallet', 'menu:wallet'))
 *       ->row(InlineKeyboard::url('Support', 'https://t.me/...'))
 *       ->build();
 */
final class InlineKeyboard
{
    /** @var list<list<array<string, mixed>>> */
    private array $rows = [];

    public static function make(): self
    {
        return new self();
    }

    /**
     * @param string|null $style One of KeyboardLayout::STYLES ("primary" blue, "success" green, "danger" red); null = the client's default
     * @param string|null $icon A premium emoji's id, shown before the text (KeyboardLayout's button icon); null = none
     * @return array<string, mixed>
     */
    public static function callback(string $text, string $data, ?string $style = null, ?string $icon = null): array
    {
        $button = $style === null ? ['text' => $text, 'callback_data' => $data] : ['text' => $text, 'callback_data' => $data, 'style' => $style];

        return $icon === null ? $button : $button + ['icon_custom_emoji_id' => $icon];
    }

    /** @return array<string, mixed> */
    public static function url(string $text, string $url): array
    {
        return ['text' => $text, 'url' => $url];
    }

    /** @param array<string, mixed> ...$buttons */
    public function row(array ...$buttons): self
    {
        if ($buttons !== []) {
            $this->rows[] = array_values($buttons);
        }

        return $this;
    }

    /**
     * Lay buttons out N per row.
     *
     * @param list<array<string, mixed>> $buttons
     */
    public function grid(array $buttons, int $perRow = 2): self
    {
        foreach (array_chunk($buttons, max(1, $perRow)) as $chunk) {
            $this->row(...$chunk);
        }

        return $this;
    }

    /** @return array{inline_keyboard: list<list<array<string, mixed>>>} */
    public function build(): array
    {
        return ['inline_keyboard' => $this->rows];
    }
}
