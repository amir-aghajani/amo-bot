<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;

/**
 * One field of a form (Form): the name a screen sends and shows it under, the key it is kept under — a
 * settings-table key, or a config.php setting —, what it is while nothing is kept, how a form's value is checked (FieldRefused
 * with the message under the field, in the admin's words) and how a kept value reads back: one the field would refuse
 * (written by hand, or under another rule) reads as its default. With a spec (FieldSpec) it describes itself to a
 * generic form (describe()): a driver's form is drawn from that alone.
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
     * What this field's value says against the others' (all checked on their own already), or null when nothing.
     *
     * @param array<string, mixed> $values The form's values as the save would leave them, by field name — a field
     *                                     refused, or not shown (FieldSpec::$when), is not there
     */
    public function conflict(array $values): ?string
    {
        return null;
    }

    /**
     * What keeping the stored value says against the save while what it belongs with moved — a secret left blank while
     * a field it is bound to moved (Secret::$boundTo) —, or null when nothing. A field that stands in for another hands
     * this on, as it does conflict().
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $values The form's values as the save would leave them, by field name — a field
     *                                     refused, or not shown, is not there: it does not move
     * @param array<string, mixed> $before What each field held before, by name
     */
    public function leftBehind(array $input, array $values, array $before): ?string
    {
        return null;
    }

    /**
     * The field as a generic form draws it: what it holds, its words, where it is shown, its bounds and what it holds
     * while nothing is kept — never a secret's.
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
     * The values it may take and their words, in order (a choice's).
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
     * The least and the most a number of it may be — a number's, each of a list's, an amount's —; nulls where it has
     * no bounds.
     *
     * @return array{0: int|float|null, 1: int|float|null}
     */
    protected function bounds(): array
    {
        return [null, null];
    }

    /** What its numbers are counted in («ثانیه»), when its messages say it. */
    protected function unit(): ?string
    {
        return null;
    }
}
