<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Core\Security\Encrypter;
use App\Support\Persian;

/**
 * Fields in the API's described shape (Core\Forms\Form::describe()) and a draft of them as the bot holds it, read the
 * way the panel's driver-form/draft.ts reads it — what a driver's form (Owner\Forms\DescribedForm) and a form the panel
 * saves whole (Panel\Forms\DraftForm) share: a draft starts from what is kept, else each field's default, every secret
 * blank; a field is shown (and sent) only while the fields its `when` names hold one of the listed values; a typed
 * secret waits in the draft encrypted — the chat's state is no place for it —; and a blank secret no longer keeps the
 * stored one once a field it is bound to moved. How a bound field's value is compared is the form's own (identity()).
 */
trait DescribedFields
{
    /** What a typed secret is kept under in the draft, before its ciphertext. */
    private const SEALED = 'enc:';

    /**
     * What identifies a field's value as the API compares a bound secret's fields (an address by its origin, a number
     * by its digits).
     *
     * @param array<string, mixed> $field
     */
    abstract private static function identity(array $field, mixed $value): string;

    /** @param array<string, mixed> $field */
    public static function name(array $field): string
    {
        return is_string($field['name'] ?? null) ? $field['name'] : '';
    }

    /** @param array<string, mixed> $field */
    public static function isSecret(array $field): bool
    {
        return ($field['secret'] ?? false) === true || ($field['type'] ?? '') === 'secret';
    }

    /**
     * A choice's values and their labels.
     *
     * @param array<string, mixed> $field
     * @return array<string, string>
     */
    public static function options(array $field): array
    {
        $options = [];
        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
            if (is_array($option) && is_scalar($option['value'] ?? null)) {
                $options[(string) $option['value']] = is_string($option['label'] ?? null) ? $option['label'] : (string) $option['value'];
            }
        }

        return $options;
    }

    /** A typed secret as the draft keeps it: encrypted, while the app has a key. */
    public static function seal(string $secret, Encrypter $crypt): string
    {
        return $crypt->isReady() ? self::SEALED . $crypt->encrypt($secret) : $secret;
    }

    /** A secret the draft keeps, as typed. */
    public static function unseal(string $kept, Encrypter $crypt): string
    {
        if (!str_starts_with($kept, self::SEALED)) {
            return $kept;
        }

        try {
            return $crypt->decrypt(substr($kept, strlen(self::SEALED)));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Values and their labels in the API's shape of a choice's options: [{value, label}].
     *
     * @param array<int|string, string> $words
     * @return list<array{value: string, label: string}>
     */
    private static function pairs(array $words): array
    {
        return array_map(static fn(int|string $value, string $label): array => ['value' => (string) $value, 'label' => $label], array_keys($words), array_values($words));
    }

    /**
     * What a draft of `$fields` starts from: what is kept for each (`$stored`, as the API presents it), else its default
     * — a list typed out ("50, 100"), a switch on or off — and every secret blank, not cleared.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $stored
     * @return array<string, string|bool>
     */
    private static function startOf(array $fields, array $stored): array
    {
        $draft = [];
        foreach ($fields as $field) {
            $name = self::name($field);
            if (self::isSecret($field)) {
                $draft[$name] = '';
                $draft['clear_' . $name] = false;

                continue;
            }
            $kept = $stored[$name] ?? null;
            $value = $kept === null || (is_array($kept) && !array_is_list($kept)) ? ($field['default'] ?? null) : $kept;
            $draft[$name] = match (true) {
                ($field['type'] ?? '') === 'toggle' => $value === true,
                is_array($value) => implode(', ', array_map(static fn(mixed $item): string => is_scalar($item) ? (string) $item : '', $value)),
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => '',
            };
        }

        return $draft;
    }

    /**
     * The fields of `$fields` shown, in order, by their index: each whose `when` holds — every field it names shown
     * before it and holding one of the listed values (a switch as "true"/"false", a number as its Latin digits).
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, string|bool> $values
     * @return array<int, array<string, mixed>>
     */
    private static function shownOf(array $fields, array $values): array
    {
        $shown = [];
        $names = [];
        foreach ($fields as $index => $field) {
            $holds = true;
            foreach (is_array($field['when'] ?? null) ? $field['when'] : [] as $name => $allowed) {
                $value = $values[(string) $name] ?? '';
                $spelled = is_bool($value) ? ($value ? 'true' : 'false') : trim(Persian::latinDigits($value));
                if (!isset($names[(string) $name]) || !in_array($spelled, array_map(strval(...), (array) $allowed), true)) {
                    $holds = false;

                    break;
                }
            }
            if ($holds) {
                $shown[$index] = $field;
                $names[self::name($field)] = true;
            }
        }

        return $shown;
    }

    /**
     * Whether `$field`, a secret kept (`$stored`: the form's values as the API presents them), would keep it blank
     * although a shown field it is bound to (`bound_to`) moved — which the API refuses in the field's `moved` words.
     *
     * @param array<string, mixed> $field
     * @param list<array<string, mixed>> $fields The form's fields
     * @param array<string, string|bool> $values
     * @param array<string, mixed> $stored
     */
    private static function movedOf(array $field, array $fields, array $values, array $stored): bool
    {
        $name = self::name($field);
        $kept = $stored[$name] ?? null;
        if (!self::isSecret($field) || !is_array($kept) || ($kept['set'] ?? false) !== true || ($values[$name] ?? '') !== '' || ($values['clear_' . $name] ?? false) === true) {
            return false;
        }
        $shown = [];
        foreach (self::shownOf($fields, $values) as $each) {
            $shown[self::name($each)] = $each;
        }
        foreach (is_array($field['bound_to'] ?? null) ? $field['bound_to'] : [] as $bound) {
            $bound = (string) $bound;
            $was = $stored[$bound] ?? null;
            if (isset($shown[$bound]) && is_scalar($was) && self::identity($shown[$bound], $values[$bound] ?? '') !== self::identity($shown[$bound], $was)) {
                return true;
            }
        }

        return false;
    }
}
