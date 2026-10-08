<?php

declare(strict_types=1);

namespace App\Core\Forms;

/**
 * What a field holds, as a generic form draws it (Fields\Field::describe()): a line of text, a whole number, an amount
 * of Toman, a short list of numbers typed as text, an on/off switch, one of a few values, a secret, an email address,
 * a web address, a file's path, a few lines of text, or a bank card's number (drawn in groups of four, as it is
 * printed, and typed on a phone's number pad).
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
}
