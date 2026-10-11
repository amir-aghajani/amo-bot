<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;

/**
 * One field of a Form: the name a screen uses, the key it is kept under (a settings key, a config.php setting, a
 * column), its default, how a submitted value is checked (FieldRefused, worded for the admin) and how a kept value
 * reads back — a kept value the field would refuse reads as the default. With a FieldSpec it can describe itself.
 *
 * @template T
 */
abstract class Field
{
    /** @param T $default */
    public function __construct(
        public readonly string $name,
        public readonly string $key,
        public readonly mixed $default,
        /** How a generic form draws it; null for a field only its own screen draws (a settings card's). */
        public readonly ?FieldSpec $spec = null,
    ) {}

    /** What it holds, as a generic form draws it. */
    abstract public function type(): FieldType;

    /** Whether a form that leaves it empty is refused — for a secret, whether one must end up kept. */
    public function required(): bool
    {
        return false;
    }

    /**
     * The form's value, checked.
     *
     * @param array<string, mixed> $input
     * @param mixed $kept What is kept under the key now (a secret left blank keeps it)
     * @return T
     * @throws FieldRefused
     */
    abstract public function read(array $input, mixed $kept): mixed;

    /**
     * A kept value as the field holds it; null — nothing kept — is the default.
     *
     * @return T
     */
    abstract public function cast(mixed $kept): mixed;

    /**
     * The value as a screen shows it.
     *
     * @param T $value
     */
    public function present(mixed $value): mixed
    {
        return $value;
    }

    /**
     * An error about this field's value against the others' (each already checked alone), or null.
     *
     * @param array<string, mixed> $values The form's values after the save, by field name; refused or hidden fields are absent
     */
    public function conflict(array $values): ?string
    {
        return null;
    }

    /**
     * An error about keeping the stored value while the fields it belongs with changed (a secret left blank while its
     * address moved — Secret::$boundTo), or null. A field that wraps another passes this on, as it does conflict().
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $values The form's values after the save, by field name; refused or hidden fields are absent
     * @param array<string, mixed> $before What each field held before, by name
     */
    public function leftBehind(array $input, array $values, array $before): ?string
    {
        return null;
    }

    /**
     * The field as a generic form draws it: type, words, visibility, bounds and default (never a secret's).
     *
     * @return array{name: string, type: string, label: string, hint: string|null, placeholder: string|null, required: bool, secret: bool, bound_to: list<string>, moved: string|null, advanced: bool, options: list<array{value: string, label: string}>, when: object, unit: string|null, min: int|float|null, max: int|float|null, ltr: bool, default: mixed}
     * @throws \LogicException for a field without a spec: every field of a described form has one
     */
    public function describe(): array
    {
        $spec = $this->spec ?? throw new \LogicException("The field \"{$this->name}\" has no FieldSpec: a form cannot draw it.");
        [$min, $max] = $this->bounds();

        return [
            'name' => $this->name,
            'type' => $this->type()->value,
            'label' => $spec->label,
            'hint' => $spec->hint,
            'placeholder' => $spec->placeholder,
            'required' => $this->required(),
            'secret' => false,
            'bound_to' => [],
            'moved' => null,
            'advanced' => $spec->advanced,
            'options' => $this->options(),
            'when' => (object) $spec->when,
            'unit' => $spec->unit ?? $this->unit(),
            'min' => $min,
            'max' => $max,
            'ltr' => $spec->ltr,
            'default' => $this->present($this->default),
        ];
    }

    /**
     * A choice's values and labels, in order.
     *
     * @return list<array{value: string, label: string}>
     */
    protected function options(): array
    {
        $options = [];
        foreach ($this->spec->options ?? [] as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * The least and the most its numbers may be (a number, each of a list, an amount); nulls for no bound.
     *
     * @return array{0: int|float|null, 1: int|float|null}
     */
    protected function bounds(): array
    {
        return [null, null];
    }

    /** The unit its numbers are counted in («ثانیه»), if any. */
    protected function unit(): ?string
    {
        return null;
    }
}
