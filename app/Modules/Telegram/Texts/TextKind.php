<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

/**
 * Where a text ends up, which decides what it may hold: a message or the QR card's caption (Telegram HTML), a popup
 * or a button (plain text), or a part another text takes in as a variable.
 */
enum TextKind: string
{
    case Message = 'message';
    case Caption = 'caption';
    case Popup = 'popup';
    case Button = 'button';
    case Part = 'part';

    /** The most a wording may show (tags not counted): Telegram's limit, less room for what its variables bring. */
    public function limit(): int
    {
        return match ($this) {
            self::Message => 3500,
            self::Caption => 850,
            self::Popup => 190,
            self::Button => 60,
            self::Part => 500,
        };
    }

    /** Whether Telegram reads it as HTML; a popup and a button are shown as typed. */
    public function html(): bool
    {
        return $this !== self::Popup && $this !== self::Button;
    }
}
