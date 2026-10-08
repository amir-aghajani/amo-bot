<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

/**
 * A piece of Telegram HTML that goes into a text as it is — a part the admin worded (BotTexts::part()), or lines of
 * them — where any other value BotTexts::render() takes is plain text and is escaped on the way in. Escaping is
 * render()'s alone: nothing else builds an Html out of a value.
 */
final readonly class Html implements \Stringable
{
    private function __construct(public string $html) {}

    /** What BotTexts made of a part: Telegram HTML already, as TelegramHtml checked the admin's wording. */
    public static function of(string $html): self
    {
        return new self($html);
    }

    /** Pieces one under another, a line each — a list the code builds from parts (the wallet's last lines). */
    public static function lines(self ...$pieces): self
    {
        return new self(implode("\n", array_map(static fn(self $piece): string => $piece->html, $pieces)));
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
