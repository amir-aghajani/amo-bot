<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Forms;

use App\Core\Security\Encrypter;
use App\Modules\Telegram\Admin\Ui\DescribedFields;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Support\Persian;

/**
 * A form the panel saves whole — a config.php group, a driver picked with its form (the database, the email, the SMS,
 * the website's captcha), a connection to try — and a draft of it as the bot holds it until «💾 ذخیره» (DescribedFields):
 * a secret is sent only when typed, else the kept one stays — unless cleared (`clear_<name>`). Fields are in the API's
 * described shape, the bot's own ones made the same way (field()).
 */
final class DraftForm
{
    use DescribedFields;

    /** The driver that is none: no email, no SMS, no captcha. */
    public const NONE = 'none';

    /**
     * @param array<string, array{label: string, description: string, notes: list<string>, fields: list<array<string, mixed>>, traits: array<string, mixed>}> $drivers By key, in order; a form with nothing to pick has one, under ''
     * @param array<string, array<string, mixed>> $stored What the API keeps of each driver's fields, by driver (a secret as {set, hint})
     * @param array{label: string, hint: string}|null $none The choice of no driver at all, when the form offers it
     * @param list<array<string, mixed>> $common The fields every driver's form ends with (the email's sender)
     */
    public function __construct(
        /** The request's name for the driver picked (`transport`, `driver`); null for a form with nothing to pick. */
        public readonly ?string $choose,
        public readonly array $drivers,
        /** The driver saved now ('' for a form with nothing to pick, NONE for none). */
        public readonly string $saved,
        public readonly array $stored,
        public readonly ?array $none = null,
        public readonly array $common = [],
    ) {}

    /**
     * A form with nothing to pick: these fields, with what is kept of them.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $stored
     */
    public static function fixed(array $fields, array $stored): self
    {
        return new self(null, ['' => ['label' => '', 'description' => '', 'notes' => [], 'fields' => $fields, 'traits' => []]], '', ['' => $stored]);
    }

    /**
     * The drivers as the API describes them (Descriptor::toArray()), by key.
     *
     * @param list<mixed> $described
     * @return array<string, array{label: string, description: string, notes: list<string>, fields: list<array<string, mixed>>, traits: array<string, mixed>}>
     */
    public static function drivers(array $described): array
    {
        $drivers = [];
        foreach ($described as $driver) {
            if (!is_array($driver) || !is_string($driver['key'] ?? null)) {
                continue;
            }
            $drivers[$driver['key']] = [
                'label' => is_string($driver['label'] ?? null) ? $driver['label'] : $driver['key'],
                'description' => is_string($driver['description'] ?? null) ? $driver['description'] : '',
                'notes' => array_values(array_filter(is_array($driver['notes'] ?? null) ? $driver['notes'] : [], is_string(...))),
                'fields' => array_values(array_filter(is_array($driver['fields'] ?? null) ? $driver['fields'] : [], is_array(...))),
                'traits' => is_array($driver['traits'] ?? null) ? $driver['traits'] : [],
            ];
        }

        return $drivers;
    }

    /**
     * A field of the bot's own, in the API's shape — with what the API's have not: `generate` (a secret's length a tap
     * makes up, as the panel's «تولید رمز تازه»), `transient` (a secret only typed for the save, never kept: a password
     * that proves it is the owner).
     *
     * @param array<string, string> $options A choice's values and their labels
     * @param array<string, list<string>> $when
     * @return array<string, mixed>
     */
    public static function field(string $name, string $type, string $label, string $hint = '', bool $required = false, mixed $default = '', array $options = [], bool $ltr = false, string $unit = '', ?int $min = null, ?int $max = null, array $when = [], int $generate = 0, bool $transient = false): array
    {
        return [
            'name' => $name, 'type' => $type, 'label' => $label, 'hint' => $hint === '' ? null : $hint, 'placeholder' => null,
            'required' => $required, 'secret' => $type === 'secret', 'bound_to' => [], 'moved' => null, 'advanced' => false,
            'options' => self::pairs($options),
            'when' => $when, 'unit' => $unit === '' ? null : $unit, 'min' => $min, 'max' => $max, 'ltr' => $ltr, 'default' => $default,
            'generate' => $generate, 'transient' => $transient,
        ];
    }

    /**
     * The drivers to pick among, none first when offered: key => label.
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        $choices = $this->none === null ? [] : [self::NONE => $this->none['label']];
        foreach ($this->drivers as $key => $driver) {
            $choices[$key] = $driver['label'];
        }

        return $choices;
    }

    /** The driver `$key` is one of the form's (none included when offered). */
    public function has(string $key): bool
    {
        return isset($this->drivers[$key]) || ($key === self::NONE && $this->none !== null);
    }

    /**
     * Every field of `$driver`'s form, its own then the common ones; none for no driver.
     *
     * @return list<array<string, mixed>>
     */
    public function fields(string $driver): array
    {
        if (!isset($this->drivers[$driver])) {
            return [];
        }

        return [...$this->drivers[$driver]['fields'], ...$this->common];
    }

    /**
     * What `$driver`'s form starts from: what is kept for it, else each field's default.
     *
     * @return array<string, string|bool>
     */
    public function start(string $driver): array
    {
        return self::startOf($this->fields($driver), $this->stored[$driver] ?? []);
    }

    /**
     * The fields of `$driver`'s form shown, in order.
     *
     * @param array<string, string|bool> $values
     * @return array<int, array<string, mixed>> By their place among the driver's fields
     */
    public function shown(string $driver, array $values): array
    {
        return self::shownOf($this->fields($driver), $values);
    }

    /**
     * What the draft sends: the driver picked (when the form picks one) and its fields shown, as typed — a secret only
     * when typed, beside its clear flag; a transient one only when typed —, `$visible` deciding a field the form shows on
     * a condition of its own.
     *
     * @param array<string, string|bool> $values
     * @param (\Closure(array<string, mixed>): bool)|null $visible
     * @return array<string, mixed>
     */
    public function payload(string $driver, array $values, Encrypter $crypt, ?\Closure $visible = null): array
    {
        $payload = $this->choose === null ? [] : [$this->choose => $driver];
        foreach ($this->shown($driver, $values) as $field) {
            if ($visible !== null && !$visible($field)) {
                continue;
            }
            $name = self::name($field);
            $value = $values[$name] ?? (($field['type'] ?? '') === 'toggle' ? false : '');
            if (($field['transient'] ?? false) === true) {
                if ($value !== '') {
                    $payload[$name] = self::unseal((string) $value, $crypt);
                }

                continue;
            }
            if (self::isSecret($field)) {
                if ($value !== '') {
                    $payload[$name] = self::unseal((string) $value, $crypt);
                }
                $payload['clear_' . $name] = ($values['clear_' . $name] ?? false) === true;

                continue;
            }
            $payload[$name] = is_bool($value) ? $value : trim($value);
        }

        return $payload;
    }

    /**
     * Whether the draft would change anything: another driver, or what it sends unlike what the saved form sends.
     *
     * @param array<string, string|bool> $values
     */
    public function dirty(string $driver, array $values, Encrypter $crypt): bool
    {
        if ($driver !== $this->saved) {
            return true;
        }
        $unkept = static fn(array $field): bool => ($field['transient'] ?? false) !== true;

        return $this->payload($driver, $values, $crypt, $unkept) != $this->payload($driver, $this->start($driver), $crypt, $unkept);
    }

    /**
     * Whether a blank secret would keep the stored one although a field it is bound to moved, which the API refuses in
     * the field's `moved` words.
     *
     * @param array<string, mixed> $field
     * @param array<string, string|bool> $values
     */
    public function leftBehind(array $field, string $driver, array $values): bool
    {
        return self::movedOf($field, $this->fields($driver), $values, $this->stored[$driver] ?? []);
    }

    /**
     * A field's value as the card shows it (HTML).
     *
     * @param array<string, mixed> $field
     * @param array<string, string|bool> $values
     */
    public function display(array $field, string $driver, array $values): string
    {
        $name = self::name($field);
        $value = $values[$name] ?? '';
        if (self::isSecret($field)) {
            $kept = $this->stored[$driver][$name] ?? null;

            return match (true) {
                $value !== '' => '✏️ تازه نوشته شده',
                ($values['clear_' . $name] ?? false) === true => '🗑 پاک می‌شود',
                ($field['transient'] ?? false) === true => 'نوشته نشده',
                is_array($kept) && ($kept['set'] ?? false) === true => '•••• ذخیره شده' . (is_string($kept['hint'] ?? null) && $kept['hint'] !== '' && !str_starts_with($kept['hint'], '•') ? ' (' . Fmt::ltr($kept['hint']) . ')' : ''),
                default => 'ندارد',
            };
        }
        if (($field['type'] ?? '') === 'toggle') {
            return Fmt::onOff($value === true);
        }
        $text = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        if ($text === '') {
            return Fmt::NONE;
        }
        if (($field['type'] ?? '') === 'choice') {
            return Fmt::e(self::options($field)[$text] ?? $text);
        }
        $unit = is_string($field['unit'] ?? null) && $field['unit'] !== '' ? ' ' . Fmt::e($field['unit']) : '';
        if (($field['type'] ?? '') === 'number') {
            return Fmt::digits($text) . $unit;
        }

        return self::isLatin($field) ? Fmt::ltr(Fmt::cut($text, 80)) : Fmt::e(Fmt::cut($text, 80));
    }

    /** @param array<string, mixed> $field */
    public static function label(array $field): string
    {
        return is_string($field['label'] ?? null) ? $field['label'] : self::name($field);
    }

    /**
     * Whether the field holds Latin (an address, a host, a key): read with Latin digits, shown isolated.
     *
     * @param array<string, mixed> $field
     */
    public static function isLatin(array $field): bool
    {
        return ($field['ltr'] ?? false) === true || in_array($field['type'] ?? '', ['url', 'email', 'path', 'number'], true);
    }

    /**
     * What identifies a field's value as the API compares a bound secret's fields: an address its origin, a number its
     * digits, else its text trimmed.
     *
     * @param array<string, mixed> $field
     */
    private static function identity(array $field, mixed $value): string
    {
        $text = trim(Persian::latinDigits(is_scalar($value) ? (string) $value : ''));

        return match ($field['type'] ?? '') {
            'number' => (string) preg_replace('/\D+/', '', $text),
            'url' => self::origin($text),
            default => $text,
        };
    }

    /** An address's origin (scheme, host, port), lower-cased; the text itself when it is none. */
    public static function origin(string $address): string
    {
        $parts = parse_url(trim($address));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return strtolower(trim($address));
        }

        return strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    }
}
