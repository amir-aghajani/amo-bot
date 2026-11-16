<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Form;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Support\Persian;
use App\Support\Unit;

/**
 * One field of a form the bot asks as a wizard (FormScreen), as the panel's form has it: the draft's key for it, the API's
 * field it is sent as, its label — the review card's — and its question — the step's title, a question («چند درصد؟») —,
 * its hint, and how it is set: typed (text, a code, a count, a percent, an amount of money, a decimal, a day and hour), a
 * choice among buttons, several of them (`all`: none named is every one), or a switch — a record's «فعال» one said as its
 * chip (active()). `when` shows it only while another field's value is one of those; `optional` lets it be left empty
 * (`none`: what empty means); a field not `walked` is set from the review alone (the panel's «محدودیت‌های بیشتر»);
 * `quick` offers values at a tap; a typed one says its limits (range()) and an example.
 */
final class Step
{
    public const TEXT = 'text';
    public const CODE = 'code';
    public const COUNT = 'count';
    public const PERCENT = 'percent';
    public const MONEY = 'money';
    public const DECIMAL = 'decimal';
    public const CHOICE = 'choice';
    public const MULTI = 'multi';
    public const TOGGLE = 'toggle';
    public const MOMENT = 'moment';

    /** The API's field it is sent as (a refusal names it). */
    public string $field;

    /** The step's title: what it asks. */
    public string $question;

    /**
     * @var array<string, list<int|string|null>>
     */
    public array $when = [];

    public bool $optional = false;

    /** What an empty value means («بدون سقف», «از همین حالا»). */
    public string $none = Fmt::NONE;

    public bool $walked = true;

    /** MULTI: the label of every one (null); empty — at least one must be ticked. */
    public string $all = '';

    /** MOMENT: the hour a day typed alone stands for. */
    public int $hour = 0;

    public ?int $min = null;

    public ?int $max = null;

    /** TOGGLE: a record's «فعال» switch, said «🟢 فعال» / «⏸ غیرفعال» (else «🟢 روشن» / «⏸ خاموش»). */
    public bool $active = false;

    /** What an answer looks like, plain text; '' for the type's own (a moment's). */
    public string $example = '';

    /**
     * @var list<array{0: string, 1: int|string|null}> Values offered at a tap: [label, value]
     */
    public array $quick = [];

    /** @var (\Closure(mixed): ?string)|null How a value is said instead (HTML), null for the usual */
    private ?\Closure $says = null;

    /**
     * @var array<int|string, string>|null
     */
    private ?array $resolved = null;

    /**
     * @param array<int|string, string>|\Closure(): array<int|string, string> $options A choice's values and labels, or what reads them
     */
    private function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly string $label,
        public readonly string $hint,
        private readonly array|\Closure $options = [],
        public readonly string $unit = '',
    ) {
        $this->field = $key;
        $this->question = $label;
    }

    public static function text(string $key, string $label, string $hint = ''): self
    {
        return new self(self::TEXT, $key, $label, $hint);
    }

    /** Latin letters and digits (a code, a prefix), kept in capitals. */
    public static function code(string $key, string $label, string $hint = ''): self
    {
        return new self(self::CODE, $key, $label, $hint);
    }

    public static function count(string $key, string $label, string $unit = '', string $hint = ''): self
    {
        return new self(self::COUNT, $key, $label, $hint, unit: $unit);
    }

    public static function percent(string $key, string $label, string $hint = ''): self
    {
        return new self(self::PERCENT, $key, $label, $hint);
    }

    public static function money(string $key, string $label, string $hint = ''): self
    {
        return new self(self::MONEY, $key, $label, $hint);
    }

    public static function decimal(string $key, string $label, string $unit, string $hint = ''): self
    {
        return new self(self::DECIMAL, $key, $label, $hint, unit: $unit);
    }

    /**
     * @param array<int|string, string>|\Closure(): array<int|string, string> $options
     */
    public static function choice(string $key, string $label, array|\Closure $options, string $hint = ''): self
    {
        return new self(self::CHOICE, $key, $label, $hint, $options);
    }

    /**
     * @param array<int|string, string>|\Closure(): array<int|string, string> $options
     */
    public static function multi(string $key, string $label, array|\Closure $options, string $hint = ''): self
    {
        return new self(self::MULTI, $key, $label, $hint, $options);
    }

    public static function toggle(string $key, string $label, string $hint = ''): self
    {
        return new self(self::TOGGLE, $key, $label, $hint);
    }

    /** A record's «فعال» switch: «وضعیت» on the review, its chip «🟢 فعال» / «⏸ غیرفعال». */
    public static function active(string $key, string $hint = ''): self
    {
        $step = new self(self::TOGGLE, $key, 'وضعیت', $hint);
        $step->active = true;

        return $step;
    }

    /** One end of a window: a Jalali day and an hour (Moment), `$hour` for a day typed alone. */
    public static function moment(string $key, string $label, int $hour, string $hint = ''): self
    {
        $step = new self(self::MOMENT, $key, $label, $hint);
        $step->hour = $hour;

        return $step;
    }

    /** The step's title, a question: «چند درصد تخفیف؟». */
    public function asks(string $question): self
    {
        $this->question = $question;

        return $this;
    }

    /**
     * Shown only while `$key`'s value is one of `$values`.
     *
     * @param list<int|string|null> $values
     */
    public function when(string $key, array $values): self
    {
        $this->when[$key] = $values;

        return $this;
    }

    /** May be left empty, `$none` what that means. */
    public function optional(string $none): self
    {
        $this->optional = true;
        $this->none = $none;

        return $this;
    }

    /** Set from the review alone, not asked on the way through. */
    public function later(): self
    {
        $this->walked = false;

        return $this;
    }

    /** Sent to the API under `$field`. */
    public function as(string $field): self
    {
        $this->field = $field;

        return $this;
    }

    /** MULTI: none named is every one, `$label` its words. */
    public function all(string $label): self
    {
        $this->all = $label;

        return $this;
    }

    /** A whole number from `$min` to `$max`. */
    public function range(int $min, int $max): self
    {
        $this->min = $min;
        $this->max = $max;

        return $this;
    }

    /** What an answer looks like (plain text). */
    public function example(string $example): self
    {
        $this->example = $example;

        return $this;
    }

    /** A value offered at a tap. */
    public function quick(string $label, int|string|null $value): self
    {
        $this->quick[] = [$label, $value];

        return $this;
    }

    /**
     * Its value said another way (HTML; null for the usual).
     *
     * @param \Closure(mixed): ?string $says
     */
    public function says(\Closure $says): self
    {
        $this->says = $says;

        return $this;
    }

    /**
     * Its choices, read once.
     *
     * @return array<int|string, string>
     */
    public function options(): array
    {
        return $this->resolved ??= is_array($this->options) ? $this->options : ($this->options)();
    }

    /** Whether it is set at a tap (its choices, or a switch), not typed. */
    public function tapped(): bool
    {
        return in_array($this->type, [self::CHOICE, self::TOGGLE, self::MULTI], true);
    }

    /**
     * Whether it shows with `$draft` as it is (its `when`).
     *
     * @param array<string, mixed> $draft
     */
    public function shown(array $draft): bool
    {
        foreach ($this->when as $key => $allowed) {
            if (!in_array($draft[$key] ?? null, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** Whether `$value` is empty: nothing typed, nothing chosen. */
    public static function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /** What a typed answer may be, plain text: «از ۱ تا ۱۰۰ درصد»; '' for no bounds. */
    public function limits(): string
    {
        if ($this->min === null || $this->max === null) {
            return '';
        }
        $unit = match (true) {
            $this->unit !== '' => ' ' . $this->unit,
            $this->type === self::PERCENT => ' درصد',
            default => '',
        };

        return 'از ' . Fmt::number($this->min) . ' تا ' . Fmt::number($this->max) . $unit;
    }

    /** What a typed answer looks like (plain text): the step's own example, else the type's (a moment's). */
    public function exampleOf(): string
    {
        return match (true) {
            $this->example !== '' => $this->example,
            $this->type === self::MOMENT => Moment::example(),
            default => '',
        };
    }

    /** Its value as it would be typed back (its question's value now, to copy); '' for none. */
    public function typed(mixed $value): string
    {
        if (self::blank($value) || !is_scalar($value)) {
            return '';
        }

        return match ($this->type) {
            self::MOMENT => Moment::typed((string) $value),
            self::COUNT, self::PERCENT, self::MONEY, self::DECIMAL => Persian::digits((string) $value),
            default => (string) $value,
        };
    }

    /** Its value as the review says it (HTML). */
    public function display(mixed $value): string
    {
        if ($this->says !== null) {
            $said = ($this->says)($value);
            if ($said !== null) {
                return $said;
            }
        }
        if ($this->type === self::TOGGLE) {
            return $this->active ? Fmt::active((bool) $value) : Fmt::onOff((bool) $value);
        }
        if ($this->type === self::MULTI) {
            if (!is_array($value)) {
                return Fmt::e($this->all !== '' ? $this->all : $this->none);
            }
            $options = $this->options();
            $names = array_map(static fn(mixed $one): string => $options[is_int($one) || is_string($one) ? $one : ''] ?? Fmt::digits(is_scalar($one) ? (string) $one : ''), $value);

            return $names === [] ? 'هیچ‌کدام' : Fmt::e(Fmt::cut(implode('، ', $names), 160));
        }
        if (self::blank($value) && $this->type !== self::CHOICE) {
            return Fmt::e($this->none);
        }
        $text = is_scalar($value) ? (string) $value : '';
        $unit = $this->unit !== '' ? ' ' . Fmt::e($this->unit) : '';

        return match ($this->type) {
            self::CODE => Fmt::code($text),
            self::COUNT => Fmt::number($text) . $unit,
            self::PERCENT => Fmt::percent($text),
            self::MONEY => Fmt::money($text),
            self::DECIMAL => Fmt::digits(str_replace('.', '٫', $text)) . $unit,
            self::MOMENT => Moment::words($text),
            self::CHOICE => Fmt::e($this->options()[$value === null ? '' : $text] ?? ($value === null ? $this->none : $text)),
            default => Fmt::e(Fmt::cut($text, 120)),
        };
    }

    /**
     * What was typed for it, as the draft keeps it.
     *
     * @throws ApiRefusal 422 under its field: not what it takes, the question still waiting
     */
    public function read(Answer $answer): int|string
    {
        return match ($this->type) {
            self::CODE => mb_strtoupper(str_replace(' ', '', $answer->latin())),
            self::COUNT, self::PERCENT => $this->whole($answer->integer()),
            self::MONEY => $answer->amount(Unit::Toman) ?? throw $this->refused(Words::NOT_TOMAN),
            self::DECIMAL => self::positive($answer->decimal()) ?? throw $this->refused('یک عدد بنویسید، مثل ۵ یا ۲.۵.'),
            self::MOMENT => Moment::parse($answer->text(), $this->hour) ?? throw $this->refused('روز را به تقویم شمسی بنویسید، مثل ' . Moment::example() . ' (ساعت بعد از روز، اگر بخواهید).'),
            default => $answer->text(),
        };
    }

    /** A whole number in its range. */
    private function whole(?int $value): int
    {
        $range = $this->min !== null && $this->max !== null ? 'عددی بین ' . Fmt::number($this->min) . ' تا ' . Fmt::number($this->max) . ' بنویسید.' : Words::NOT_NUMBER;
        if ($value === null || ($this->min !== null && $value < $this->min) || ($this->max !== null && $value > $this->max)) {
            throw $this->refused($range);
        }

        return $value;
    }

    /** A decimal above zero, without trailing zeros ("2.50" → "2.5"); null for none. */
    private static function positive(?string $value): ?string
    {
        return $value === null || (float) $value <= 0 ? null : Marketing::trim($value);
    }

    private function refused(string $message): ApiRefusal
    {
        return ApiRefusal::typed($this->field, $message);
    }
}
