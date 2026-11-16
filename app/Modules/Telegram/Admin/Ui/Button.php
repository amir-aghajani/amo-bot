<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Modules\Telegram\Keyboard\KeyboardLayout;

/**
 * One inline button of a management screen: to a screen's action (Nav), a link, or a value copied at a tap (an address,
 * a key, a code the owner pastes elsewhere). A button to an action may have a colour, as the customer keyboards have
 * them (KeyboardLayout::STYLES) — `success` for what confirms, saves, creates or turns on; `danger` for what deletes,
 * turns off or rejects; `primary` for the screen's one main action; a button is never changed, each of these is a new
 * one:
 *
 *   Button::to(Words::EDIT, 'plv', 'edit', 12)->primary()
 *   Button::copy(Words::copy('آدرس'), $address)
 */
final class Button
{
    /** The longest label shown whole; a longer one is cut with «…» (a name, a plan's title). */
    public const LABEL_MOST = 48;

    /** The most Telegram copies at a tap: a value past it (or none) gets no copy button (fits()). */
    public const COPY_MOST = 256;

    private function __construct(
        public readonly string $label,
        public readonly ?string $data,
        public readonly ?string $url,
        public readonly ?string $copied = null,
        public readonly ?string $style = null,
    ) {}

    /** A button to `$screen`'s `$action` with its arguments. */
    public static function to(string $label, string $screen, string $action = Nav::SHOW, int|string ...$args): self
    {
        return new self(Fmt::cut($label, self::LABEL_MOST), Nav::to($screen, $action, ...$args), null);
    }

    /** A button that opens a link. */
    public static function url(string $label, string $url): self
    {
        return new self(Fmt::cut($label, self::LABEL_MOST), null, $url);
    }

    /**
     * A button that copies `$text`, whole, to the admin's clipboard — shown only while Telegram copies it whole
     * (fits()): a key or a link cut short would be a wrong one.
     */
    public static function copy(string $label, string $text): self
    {
        return new self(Fmt::cut($label, self::LABEL_MOST), null, null, $text);
    }

    /**
     * The button again from Telegram's shape of it (markup(), as a waiting question keeps its buttons) — its colour too;
     * null for a shape that is no button. What else the shape carries is left.
     *
     * @param array<mixed> $markup
     */
    public static function kept(array $markup): ?self
    {
        $label = $markup['text'] ?? null;
        if (!is_string($label) || $label === '') {
            return null;
        }
        $style = KeyboardLayout::style(is_string($markup['style'] ?? null) ? $markup['style'] : null);
        $copy = is_array($markup['copy_text'] ?? null) ? ($markup['copy_text']['text'] ?? null) : null;

        return match (true) {
            is_string($markup['callback_data'] ?? null) => new self($label, $markup['callback_data'], null, null, $style),
            is_string($markup['url'] ?? null) => new self($label, null, $markup['url']),
            is_string($copy) => new self($label, null, null, $copy),
            default => null,
        };
    }

    /**
     * Whether it is worth a button: any but one copying nothing, or more than Telegram copies (COPY_MOST) — a key or a
     * link cut short is a wrong one. View leaves such a one out; the value is on the screen to copy all the same.
     */
    public function fits(): bool
    {
        return $this->copied === null || ($this->copied !== '' && mb_strlen($this->copied) <= self::COPY_MOST);
    }

    /** It green: what confirms, saves, creates, turns on or approves. */
    public function success(): self
    {
        return $this->styled('success');
    }

    /** It red: what deletes, turns off, rejects or disconnects — a confirmation of one too. */
    public function danger(): self
    {
        return $this->styled('danger');
    }

    /** It blue: the screen's one main action. */
    public function primary(): self
    {
        return $this->styled('primary');
    }

    /** It with `$style` (one of KeyboardLayout::STYLES; null for the client's own look). */
    public function styled(?string $style): self
    {
        return new self($this->label, $this->data, $this->url, $this->copied, KeyboardLayout::style($style));
    }

    /** It under another label, the rest kept: a question's way out, said in the management's one word (Words::CANCEL). */
    public function labelled(string $label): self
    {
        return new self(Fmt::cut($label, self::LABEL_MOST), $this->data, $this->url, $this->copied, $this->style);
    }

    /**
     * Telegram's shape of it. A colour goes on a button to an action only, as the customer keyboards put it.
     *
     * @return array<string, mixed>
     */
    public function markup(): array
    {
        if ($this->url !== null) {
            return ['text' => $this->label, 'url' => $this->url];
        }
        if ($this->copied !== null) {
            return ['text' => $this->label, 'copy_text' => ['text' => $this->copied]];
        }
        $markup = ['text' => $this->label, 'callback_data' => (string) $this->data];
        if ($this->style !== null) {
            $markup['style'] = $this->style;
        }

        return $markup;
    }
}
