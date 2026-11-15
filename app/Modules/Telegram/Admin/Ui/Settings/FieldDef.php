<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui\Settings;

use App\Modules\Telegram\Admin\Ui\Fmt;

/**
 * One field of a settings card in the bot (SettingsScreen), as the panel's card shows it: the API's name for it, its
 * label, a hint, and how it is set — a switch flipped at a tap, a choice among buttons (or several: `multi`), a text, a
 * number, an amount of money, a list of numbers, an address or a secret typed. Its label, hint and choices are the
 * panel's words for it (the panel's card is where they come from). `when` shows it only while another field's value
 * is one of those (the panel's own conditions); `nullable` offers «🗑 خالی کردن».
 */
final class FieldDef
{
    public const TOGGLE = 'toggle';
    public const CHOICE = 'choice';
    public const MULTI = 'multi';
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const NUMBER = 'number';
    public const MONEY = 'money';
    public const LIST = 'list';
    public const URL = 'url';
    public const SECRET = 'secret';

    /**
     * @var array<string, list<int|string|bool>>
     */
    public array $when = [];

    public bool $nullable = false;

    /**
     * A switch's question before it is set on (1) or off (0): asks().
     *
     * @var array<int, array{question: string, yes: string, detail: string}>
     */
    public array $asks = [];

    /**
     * @param array<int|string, string> $options A choice's values and their labels
     */
    private function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $label,
        public readonly string $hint = '',
        public readonly array $options = [],
        public readonly string $unit = '',
    ) {}

    public static function toggle(string $name, string $label, string $hint = ''): self
    {
        return new self(self::TOGGLE, $name, $label, $hint);
    }

    /**
     * @param array<int|string, string> $options
     */
    public static function choice(string $name, string $label, array $options, string $hint = ''): self
    {
        return new self(self::CHOICE, $name, $label, $hint, $options);
    }

    /**
     * Several of the choices (a list of values).
     *
     * @param array<int|string, string> $options
     */
    public static function multi(string $name, string $label, array $options, string $hint = ''): self
    {
        return new self(self::MULTI, $name, $label, $hint, $options);
    }

    public static function text(string $name, string $label, string $hint = ''): self
    {
        return new self(self::TEXT, $name, $label, $hint);
    }

    /** Text of several lines (a message the bot sends). */
    public static function textarea(string $name, string $label, string $hint = ''): self
    {
        return new self(self::TEXTAREA, $name, $label, $hint);
    }

    public static function number(string $name, string $label, string $unit = '', string $hint = ''): self
    {
        return new self(self::NUMBER, $name, $label, $hint, unit: $unit);
    }

    public static function money(string $name, string $label, string $hint = ''): self
    {
        return new self(self::MONEY, $name, $label, $hint, unit: 'تومان');
    }

    /** Numbers typed in one answer ("50000, 100000"). */
    public static function list(string $name, string $label, string $unit = '', string $hint = ''): self
    {
        return new self(self::LIST, $name, $label, $hint, unit: $unit);
    }

    public static function url(string $name, string $label, string $hint = ''): self
    {
        return new self(self::URL, $name, $label, $hint);
    }

    /** A token, a password: never shown back, only whether one is kept. */
    public static function secret(string $name, string $label, string $hint = ''): self
    {
        return new self(self::SECRET, $name, $label, $hint);
    }

    /**
     * Shown only while `$field`'s value is one of `$values`.
     *
     * @param list<int|string|bool> $values
     */
    public function when(string $field, array $values): self
    {
        $this->when[$field] = $values;

        return $this;
    }

    /**
     * A switch set to `$on` asks first — one that holds customers back (the bot off) is not a tap to slip on —:
     * `$question` (plain text), what it does (`$detail`, HTML), «`$yes`» doing it and «✖️ انصراف» not.
     */
    public function asks(bool $on, string $question, string $yes, string $detail = ''): self
    {
        $this->asks[(int) $on] = ['question' => $question, 'yes' => $yes, 'detail' => $detail];

        return $this;
    }

    /** May be left empty («🗑 خالی کردن»). */
    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    /**
     * Whether it shows among `$values` (its `when`).
     *
     * @param array<string, mixed> $values
     */
    public function shown(array $values): bool
    {
        foreach ($this->when as $field => $allowed) {
            if (!in_array($values[$field] ?? null, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** Its value as the card shows it (HTML). */
    public function display(mixed $value): string
    {
        return match ($this->type) {
            self::TOGGLE => Fmt::onOff((bool) $value),
            self::CHOICE => Fmt::e($this->options[(string) (is_bool($value) ? (int) $value : $value)] ?? (string) ($value ?? Fmt::NONE)),
            self::MULTI => is_array($value) && $value !== [] ? Fmt::e(implode('، ', array_map(fn(mixed $v): string => $this->options[(string) $v] ?? (string) $v, $value))) : 'هیچ‌کدام',
            self::MONEY => $value === null || $value === '' ? Fmt::NONE : Fmt::money(is_scalar($value) ? (string) $value : '0'),
            self::NUMBER => $value === null || $value === '' ? Fmt::NONE : Fmt::number(is_numeric($value) ? $value : 0) . ($this->unit !== '' ? ' ' . Fmt::e($this->unit) : ''),
            self::LIST => is_array($value) && $value !== [] ? implode('، ', array_map(static fn(mixed $v): string => Fmt::number(is_numeric($v) ? $v : 0), $value)) . ($this->unit !== '' ? ' ' . Fmt::e($this->unit) : '') : Fmt::NONE,
            self::SECRET => $value === null || $value === '' || $value === false ? 'ندارد' : '•••• (ذخیره شده)',
            self::URL => Fmt::ltr(is_scalar($value) ? (string) $value : null),
            default => $value === null || $value === '' ? Fmt::NONE : Fmt::e(Fmt::cut(is_scalar($value) ? (string) $value : '', 60)),
        };
    }

    /** Its value as a button's label shows it (plain text, short). */
    public function brief(mixed $value): string
    {
        return match ($this->type) {
            self::TOGGLE => (bool) $value ? '✅' : '⛔️',
            self::TEXTAREA => $value === null || $value === '' ? Fmt::NONE : '✏️',
            default => Fmt::plain($this->display($value)),
        };
    }
}
