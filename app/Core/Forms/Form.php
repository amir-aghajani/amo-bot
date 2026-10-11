<?php

declare(strict_types=1);

namespace App\Core\Forms;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\Fields\Secret;

/**
 * A named set of fields saved together: a settings card (its key is the group `PUT …/{group}` saves) or a driver's
 * form. The caller keeps the values (the settings table, config.php) and hands over what is kept under each key. A save
 * reads every field, then checks the fields against each other, and refuses as a whole — every error at once, each
 * under its field — before anything is kept. A field hidden by its `when` is neither read nor refused and keeps its value.
 */
final class Form
{
    /**
     * @param list<Field<mixed>> $fields
     * @throws \LogicException for a field shown by one that is not before it, or a secret bound to one the form lacks
     */
    public function __construct(
        public readonly string $key,
        public readonly array $fields,
    ) {
        $named = [];
        foreach ($fields as $field) {
            foreach (array_keys($field->spec->when ?? []) as $name) {
                if (!isset($named[$name])) {
                    throw new \LogicException("The field \"{$field->name}\" of the form \"{$key}\" is shown by \"{$name}\", which is no field before it.");
                }
            }
            $named[$field->name] = true;
        }
        foreach ($fields as $field) {
            foreach ($field instanceof Secret ? array_keys($field->boundTo) : [] as $name) {
                if (!isset($named[$name])) {
                    throw new \LogicException("The secret \"{$field->name}\" of the form \"{$key}\" is bound to \"{$name}\", which is no field of it.");
                }
            }
        }
    }

    /**
     * The `$kept` reader for a driver's form: `$values` (kept for driver `$keptFor`) while `$driver` is that driver,
     * nothing otherwise — so one driver's kept secret never stands in for another driver's field of the same name.
     *
     * @param array<string, mixed> $values By key
     * @return \Closure(Field<mixed>): mixed
     */
    public static function keptFor(string $driver, ?string $keptFor, array $values): \Closure
    {
        $kept = $driver === $keptFor ? $values : [];

        return static fn(Field $field): mixed => $kept[$field->key] ?? null;
    }

    /** The form narrowed to these fields, for a step that sets part of it (the installer's site step). */
    public function only(string ...$names): self
    {
        return new self($this->key, array_values(array_filter($this->fields, static fn(Field $field): bool => in_array($field->name, $names, true))));
    }

    /**
     * Every field as a generic form draws it.
     *
     * @return list<array<string, mixed>>
     * @throws \LogicException for a field without a spec: the code's mistake
     */
    public function describe(): array
    {
        return array_map(static fn(Field $field): array => $field->describe(), $this->fields);
    }

    /**
     * Every field's value as read from what is kept — what a driver works with.
     *
     * @param \Closure(Field<mixed>): mixed $kept What is kept under a field's key (null: nothing)
     * @return array<string, mixed> By field name
     */
    public function values(\Closure $kept): array
    {
        $values = [];
        foreach ($this->fields as $field) {
            $values[$field->name] = $field->cast($kept($field));
        }

        return $values;
    }

    /**
     * Every field as the screen shows it.
     *
     * @param \Closure(Field<mixed>): mixed $kept What is kept under a field's key (null: nothing)
     * @return array<string, mixed> By field name
     */
    public function present(\Closure $kept): array
    {
        $values = [];
        foreach ($this->fields as $field) {
            $values[$field->name] = $field->present($field->cast($kept($field)));
        }

        return $values;
    }

    /**
     * Check a whole save: the values to keep, by key (a hidden field is not among them: its kept value stays).
     *
     * @param array<string, mixed> $input
     * @param \Closure(Field<mixed>): mixed $kept What is kept under a field's key (null: nothing)
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function check(array $input, \Closure $kept): array
    {
        return $this->judge($input, $kept, sentOnly: false);
    }

    /**
     * Check a partial save (a PATCH): only the fields the input names, or their `clear_<name>`, are read; the rest keep
     * their value, and the cross-field checks see the whole form as the save would leave it. Returns the fields read.
     *
     * @param array<string, mixed> $input
     * @param \Closure(Field<mixed>): mixed $kept What is kept under a field's key (null: nothing)
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function checkSent(array $input, \Closure $kept): array
    {
        return $this->judge($input, $kept, sentOnly: true);
    }

    /**
     * @param array<string, mixed> $input
     * @param \Closure(Field<mixed>): mixed $kept
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function judge(array $input, \Closure $kept, bool $sentOnly): array
    {
        $stored = [];
        $before = [];
        foreach ($this->fields as $field) {
            $stored[$field->name] = $kept($field);
            $before[$field->name] = $field->cast($stored[$field->name]);
        }

        // What each field holds once saved — read, or kept as it is —, by name: a field refused or not shown is not here.
        $values = [];
        $read = [];
        $errors = [];
        foreach ($this->fields as $field) {
            if (!self::shown($field, $values)) {
                continue;
            }
            if ($sentOnly && !array_key_exists($field->name, $input) && !array_key_exists('clear_' . $field->name, $input)) {
                $values[$field->name] = $before[$field->name];

                continue;
            }
            try {
                $values[$field->name] = $field->read($input, $stored[$field->name]);
                $read[] = $field;
            } catch (FieldRefused $refused) {
                $errors[$field->name] = [$refused->getMessage()];
            }
        }
        foreach ($this->fields as $field) {
            if (!array_key_exists($field->name, $values)) {
                continue;
            }
            $conflict = $field->conflict($values) ?? $field->leftBehind($input, $values, $before);
            if ($conflict !== null) {
                $errors[$field->name] = [$conflict];
            }
        }
        ValidationException::ifAny($errors);

        $keep = [];
        foreach ($read as $field) {
            $keep[$field->key] = $values[$field->name];
        }

        return $keep;
    }

    /**
     * Whether the field is shown: every field its `when` names holds one of the listed values (a switch spelled "true"
     * or "false", a number as its digits).
     *
     * @param Field<mixed> $field
     * @param array<string, mixed> $values The fields before it, as the save would leave them
     */
    private static function shown(Field $field, array $values): bool
    {
        foreach ($field->spec->when ?? [] as $name => $allowed) {
            $value = $values[$name] ?? null;
            $spelled = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : null);
            if ($spelled === null || !in_array($spelled, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
