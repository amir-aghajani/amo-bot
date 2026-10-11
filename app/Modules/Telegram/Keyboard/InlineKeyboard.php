<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Keyboard;

/**
 * Fluent builder for inline keyboards (buttons under a message). A row's buttons go left to right as Telegram lays
 * them out, so for a Persian reader the first one sits on the left.
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

    /**
     * Lay buttons out by how many share each one's row (a plan's or server's `button_row`, 1 to ROW_MOST): consecutive
     * buttons of one width fill rows of that many; a different width starts a new row. Each row is filled in reading
     * order, the first button rightmost.
     *
     * @param list<array{array<string, mixed>, int}> $buttons Each button, and how many share its row
     */
    public function flow(array $buttons): self
    {
        $row = [];
        $size = 1;
        foreach ($buttons as [$button, $perRow]) {
            $perRow = max(1, min(KeyboardLayout::ROW_MOST, $perRow));
            if ($row !== [] && ($perRow !== $size || count($row) === $size)) {
                $this->row(...array_reverse($row));
                $row = [];
            }
            $row[] = $button;
            $size = $perRow;
        }

        return $this->row(...array_reverse($row));
    }

    /** @return array{inline_keyboard: list<list<array<string, mixed>>>} */
    public function build(): array
    {
        return ['inline_keyboard' => $this->rows];
    }
}
