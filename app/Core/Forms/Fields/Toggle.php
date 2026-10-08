<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\Input;
use App\Support\Validation;

/**
 * An on/off setting. A form must send it as a boolean (true/false, 1/0): missing or anything else is refused — never
 * read as "off".
 *
 * @extends Field<bool>
 */
final class Toggle extends Field
{
    public function __construct(string $name, string $key, bool $default, ?FieldSpec $spec = null)
    {
        parent::__construct($name, $key, $default, $spec);
    }

    public function type(): FieldType
    {
        return FieldType::Toggle;
    }

    public function required(): bool
    {
        return true;
    }

    public function read(array $input, mixed $kept): bool
    {
        $value = $input[$this->name] ?? null;
        if (!Input::isBoolean($value)) {
            throw new FieldRefused(Validation::NOT_A_SWITCH);
        }

        return Input::truthy($value);
    }

    public function cast(mixed $kept): bool
    {
        return Input::isBoolean($kept) ? Input::truthy($kept) : $this->default;
    }
}
