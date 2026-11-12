<?php

declare(strict_types=1);

namespace App\Core\Forms;

/**
 * What a field holds, which decides how a generic form draws it. `List` is numbers typed as text; `Card` is a bank
 * card's number, drawn in groups of four and typed on a phone's number pad; `PremiumEmoji` is a premium emoji picked
 * from the bot's list for a name of the form (Telegram\Emoji\PremiumEmojiField).
 */
enum FieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Amount = 'amount';
    case List = 'list';
    case Toggle = 'toggle';
    case Choice = 'choice';
    case Secret = 'secret';
    case Email = 'email';
    case Url = 'url';
    case Path = 'path';
    case Textarea = 'textarea';
    case Card = 'card';
    case PremiumEmoji = 'premium_emoji';
}
