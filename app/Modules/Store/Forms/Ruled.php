<?php

declare(strict_types=1);

namespace App\Modules\Store\Forms;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\FieldType;

/**
 * A field of the website's form with a rule of the form's own — what its value says against the others', as the save
 * would leave them (`$rule`: the refusal under it, in the owner's words, or null) —, everything else its field's: a
 * switch on that needs another field (Telegram sign-in, its Client ID), a field another's switch needs (the website on,
 * its address).
 *
 * @template T
 * @extends Field<T>
 */
final class Ruled extends Field
{
    /**
     * @param Field<T> $field
     * @param \Closure(array<string, mixed>): ?string $rule
     */
    public function __construct(
        private readonly Field $field,
        private readonly \Closure $rule,
    ) {
        parent::__construct($field->name, $field->key, $field->default, $field->spec);
    }

    public function type(): FieldType
    {
        return $this->field->type();
    }

    public function required(): bool
    {
        return $this->field->required();
    }

    public function read(array $input, mixed $kept): mixed
    {
        return $this->field->read($input, $kept);
    }

    public function cast(mixed $kept): mixed
    {
        return $this->field->cast($kept);
    }

    public function present(mixed $value): mixed
    {
        return $this->field->present($value);
    }

    public function conflict(array $values): ?string
    {
        return ($this->rule)($values) ?? $this->field->conflict($values);
    }

    public function leftBehind(array $input, array $values, array $before): ?string
    {
        return $this->field->leftBehind($input, $values, $before);
    }

    public function describe(): array
    {
        return $this->field->describe();
    }
}
