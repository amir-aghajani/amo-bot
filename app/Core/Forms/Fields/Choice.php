<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\Input;

/**
 * One of a fixed list of values (a log level, a time zone). Described, its spec words every value, in the list's order.
 *
 * @extends Field<string>
 */
final class Choice extends Field
{
    /** @param list<string> $options */
    public function __construct(
        string $name,
        string $key,
        string $default,
        public readonly array $options,
        /** What anything else is told */
        private readonly string $refusal,
        ?FieldSpec $spec = null,
    ) {
        parent::__construct($name, $key, $default, $spec);
    }

    public function type(): FieldType
    {
        return FieldType::Choice;
    }

    public function required(): bool
    {
        return true;
    }

    public function read(array $input, mixed $kept): string
    {
        $value = Input::text($input, $this->name);
        if (!in_array($value, $this->options, true)) {
            throw new FieldRefused($this->refusal);
        }

        return $value;
    }

    public function cast(mixed $kept): string
    {
        return is_string($kept) && in_array($kept, $this->options, true) ? $kept : $this->default;
    }

    /** @throws \LogicException when the spec's words are not the list's values, in its order */
    protected function options(): array
    {
        if (array_map('strval', array_keys($this->spec->options ?? [])) !== $this->options) {
            throw new \LogicException("The choice \"{$this->name}\" is described by words for each of its values, in their order.");
        }

        return parent::options();
    }
}
