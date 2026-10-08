<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\Email;
use App\Support\Input;

/**
 * An email address, by the one rule for one (App\Support\Email: kept in lower case) — blank for none, unless it is
 * `required` (an address to send from, once mail goes out at all).
 *
 * @extends Field<string>
 */
final class EmailAddress extends Field
{
    public function __construct(
        string $name,
        string $key,
        string $default,
        /** What the messages call it */
        public readonly string $label,
        private readonly bool $required = false,
        ?FieldSpec $spec = null,
    ) {
        parent::__construct($name, $key, $default, $spec);
    }

    public function type(): FieldType
    {
        return FieldType::Email;
    }

    public function required(): bool
    {
        return $this->required;
    }

    public function read(array $input, mixed $kept): string
    {
        $typed = Input::text($input, $this->name);
        if ($typed === '') {
            return $this->required ? throw new FieldRefused("{$this->label} را وارد کنید.") : '';
        }
        $problem = Email::problem($typed, $this->label);
        if ($problem !== null) {
            throw new FieldRefused($problem);
        }

        return (string) Email::of($typed);
    }

    public function cast(mixed $kept): string
    {
        return is_string($kept) ? (Email::of($kept) ?? $this->default) : $this->default;
    }
}
