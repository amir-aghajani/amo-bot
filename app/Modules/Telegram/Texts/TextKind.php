<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

/**
 * Where a text ends up, which decides what it may hold: a message or the caption of the QR card (Telegram
 * HTML), a callback popup or a button (shown as typed), or a part that another text puts inside itself.
 */
enum TextKind: string
{
    case Message = 'message';
    case Caption = 'caption';
    case Popup = 'popup';
    case Button = 'button';
    case Part = 'part';

    /**
     * The most a template may show — its text without tags, as Telegram counts —: Telegram's own limit, less room for what
     * its variables bring.
     */
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
