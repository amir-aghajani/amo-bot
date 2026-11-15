<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

/** One inline button of a management screen: to a screen's action (Nav), or a link. */
final class Button
{
    /** The longest label shown whole; a longer one is cut with «…» (a name, a plan's title). */
    public const LABEL_MOST = 48;

    private function __construct(
        public readonly string $label,
        public readonly ?string $data,
        public readonly ?string $url,
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
     * The button again from Telegram's shape of it (markup(), as a waiting question keeps its buttons); null for a shape
     * that is no button.
     *
     * @param array<mixed> $markup
     */
    public static function kept(array $markup): ?self
    {
        $label = $markup['text'] ?? null;
        if (!is_string($label) || $label === '') {
            return null;
        }
        if (is_string($markup['callback_data'] ?? null)) {
            return new self($label, $markup['callback_data'], null);
        }

        return is_string($markup['url'] ?? null) ? new self($label, null, $markup['url']) : null;
    }

    /**
     * Telegram's shape of it.
     *
     * @return array<string, string>
     */
    public function markup(): array
    {
        return $this->url !== null ? ['text' => $this->label, 'url' => $this->url] : ['text' => $this->label, 'callback_data' => (string) $this->data];
    }
}
