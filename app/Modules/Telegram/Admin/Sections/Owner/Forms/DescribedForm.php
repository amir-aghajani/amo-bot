<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Forms;

use App\Core\Security\Encrypter;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\DescribedFields;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Texts\PremiumText;
use App\Support\Persian;
use App\Support\Unit;

/**
 * A driver's form as the API describes it (Core\Forms\Form::describe(): GET /servers/drivers, GET
 * /payment-methods/drivers), and a draft of it as the bot holds it (DescribedFields): a secret is sent only when typed,
 * else the kept one stays — unless cleared (`clear_<name>`). A screen may add fields of its own in the same shape (a
 * payment method's label).
 */
final class DescribedForm
{
    use DescribedFields;

    /** A secret kept, as the API says it (Secret::KEPT): nothing of it to show. */
    private const DOTS = '••••••••';

    /**
     * @param list<array<string, mixed>> $fields Each as Field::describe() gives it
     */
    public function __construct(
        public readonly array $fields,
        private readonly Encrypter $crypt,
    ) {}

    /**
     * A field of a screen's own, in the API's shape — with what the API's have not: `walk` false keeps it out of a new
     * draft's walk (a switch on by default), `presets` are values a tap types (the panel's 7/30 days).
     *
     * @param array<int|string, string> $options A choice's values and their labels
     * @param array<int|string, string> $presets Values a tap types, and their labels
     * @param array<string, list<string>> $when
     * @return array<string, mixed>
     */
    public static function field(string $name, string $type, string $label, string $hint = '', bool $required = false, mixed $default = '', array $options = [], bool $walk = true, array $presets = [], array $when = [], string $unit = '', string $placeholder = ''): array
    {
        return [
            'name' => $name, 'type' => $type, 'label' => $label, 'hint' => $hint === '' ? null : $hint, 'placeholder' => $placeholder === '' ? null : $placeholder,
            'required' => $required, 'secret' => false, 'bound_to' => [], 'moved' => null, 'advanced' => false, 'options' => self::pairs($options), 'when' => $when,
            'unit' => $unit === '' ? null : $unit, 'min' => null, 'max' => null, 'ltr' => false, 'default' => $default, 'walk' => $walk, 'presets' => self::pairs($presets),
        ];
    }

    /**
     * The draft a form starts from: what is kept for it (`$stored`, as the API presents it), else each field's default.
     *
     * @param array<string, mixed>|null $stored
     * @return array<string, string|bool>
     */
    public function draft(?array $stored): array
    {
        return self::startOf($this->fields, $stored ?? []);
    }

    /**
     * The fields shown, in order, by their index.
     *
     * @param array<string, string|bool> $draft
     * @return array<int, array<string, mixed>>
     */
    public function shown(array $draft): array
    {
        return self::shownOf($this->fields, $draft);
    }

    /**
     * What the form sends: its fields shown, as typed — a secret only when typed, beside its clear flag.
     *
     * @param array<string, string|bool> $draft
     * @return array<string, string|bool>
     */
    public function payload(array $draft): array
    {
        $payload = [];
        foreach ($this->shown($draft) as $field) {
            $name = self::name($field);
            $value = $draft[$name] ?? ($field['type'] === 'toggle' ? false : '');
            if (self::isSecret($field)) {
                if ($value !== '') {
                    $payload[$name] = self::unseal((string) $value, $this->crypt);
                }
                $payload['clear_' . $name] = ($draft['clear_' . $name] ?? false) === true;

                continue;
            }
            $payload[$name] = $value;
        }

        return $payload;
    }

    /**
     * Each field's label by its name, for a refusal's lines.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->fields as $field) {
            $labels[self::name($field)] = self::label($field);
        }

        return $labels;
    }

    /**
     * The field's label, its unit after it («تایم‌اوت اتصال (ثانیه)»).
     *
     * @param array<string, mixed> $field
     */
    public static function label(array $field): string
    {
        $label = is_string($field['label'] ?? null) ? $field['label'] : self::name($field);

        return is_string($field['unit'] ?? null) && $field['unit'] !== '' ? "{$label} ({$field['unit']})" : $label;
    }

    /**
     * Whether the field may be left as it is in a walk (its default, or nothing).
     *
     * @param array<string, mixed> $field
     */
    public static function optional(array $field): bool
    {
        return ($field['required'] ?? false) !== true || in_array($field['type'] ?? null, ['toggle', 'choice'], true);
    }

    /**
     * The field's value as the form shows it (HTML).
     *
     * @param array<string, mixed> $field
     * @param array<string, string|bool> $draft
     * @param array<string, mixed>|null $stored
     */
    public function display(array $field, array $draft, ?array $stored): string
    {
        $name = self::name($field);
        $value = $draft[$name] ?? '';
        if (self::isSecret($field)) {
            $kept = is_array($stored[$name] ?? null) ? $stored[$name] : [];

            return match (true) {
                $value !== '' => '•••• (تازه نوشته شده)',
                ($draft['clear_' . $name] ?? false) === true => '🗑 پاک می‌شود',
                ($kept['set'] ?? false) === true => 'ذخیره شده' . (is_string($kept['hint'] ?? null) && $kept['hint'] !== '' && $kept['hint'] !== self::DOTS ? ' ' . Fmt::ltr($kept['hint']) : ''),
                default => 'ندارد',
            };
        }
        if ($field['type'] === 'toggle') {
            return Fmt::onOff($value === true);
        }
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            return Fmt::NONE;
        }

        return match ($field['type']) {
            'choice' => Fmt::e(self::option($field, $text) ?? $text),
            'number', 'decimal' => Fmt::number(is_numeric(Persian::latinDigits($text)) ? Persian::latinDigits($text) : 0) . (is_string($field['unit'] ?? null) && $field['unit'] !== '' ? ' ' . Fmt::e($field['unit']) : ''),
            'amount' => is_numeric(Persian::latinDigits($text)) ? Fmt::money(Persian::latinDigits($text)) : Fmt::e($text),
            'card' => Fmt::ltr(trim(chunk_split(preg_replace('/\D/', '', $text) ?? '', 4, ' '))),
            'premium_emoji' => '✅ دارد',
            'url', 'email', 'path', 'list' => Fmt::ltr($text),
            'textarea' => Fmt::e(Fmt::cut($text, 60)),
            default => ($field['ltr'] ?? false) === true ? Fmt::ltr(Fmt::cut($text, 60)) : Fmt::e(Fmt::cut($text, 60)),
        };
    }

    /**
     * Whether a blank secret would keep the stored one although a field it is bound to moved, which the API refuses: its
     * `moved` words are said before the save.
     *
     * @param array<string, mixed> $field
     * @param array<string, string|bool> $draft
     * @param array<string, mixed>|null $stored
     */
    public function moved(array $field, array $draft, ?array $stored): bool
    {
        return self::movedOf($field, $this->fields, $draft, $stored ?? []);
    }

    /**
     * What an admin typed (or sent) for the field, as the draft keeps it: the fields it changes by name — a premium
     * emoji's id, and its plain emoji put at the start of the name it is for.
     *
     * @param array<string, mixed> $field
     * @param array<string, string|bool> $draft
     * @return array<string, string|bool>
     * @throws ApiRefusal 422 under the field's name for what is no value of it
     */
    public function read(array $field, Answer $answer, array $draft): array
    {
        $name = self::name($field);
        $refused = static fn(string $message): ApiRefusal => ApiRefusal::typed($name, $message);
        if ($field['type'] === 'premium_emoji') {
            return $this->emoji($field, $answer, $draft) ?? throw $refused('یک ایموجی پرمیوم بفرستید؛ ایموجی معمولی شناسه پرمیوم ندارد.');
        }
        $value = match ($field['type']) {
            'number' => (string) ($answer->integer() ?? throw $refused(Words::NOT_NUMBER)),
            'decimal' => $answer->decimal() ?? throw $refused('یک عدد بنویسید؛ مثل ۱۰ یا ۱٫۵.'),
            'amount' => (string) ($answer->amount(Unit::Toman) ?? throw $refused(Words::NOT_TOMAN)),
            'list' => $answer->numbers() !== [] ? implode(', ', $answer->numbers()) : throw $refused('عددها را با فاصله یا «،» جدا بنویسید.'),
            'card' => preg_replace('/\D/', '', $answer->latin()) ?? '',
            'url', 'email', 'path' => $answer->latin(),
            'toggle' => self::yes($answer->text()) ?? throw $refused('«بله» یا «خیر» بنویسید، یا یکی از دکمه‌ها را بزنید.'),
            'choice' => self::pick($field, $answer->text()) ?? throw $refused('یکی از دکمه‌ها را بزنید.'),
            default => self::isSecret($field) ? self::seal($answer->text(), $this->crypt) : (($field['ltr'] ?? false) === true ? $answer->latin() : $answer->text()),
        };
        $changes = [$name => $value];
        if (self::isSecret($field)) {
            $changes['clear_' . $name] = false;
        }

        return $changes;
    }

    /**
     * A choice's label for `$value`; null for none.
     *
     * @param array<string, mixed> $field
     */
    public static function option(array $field, string $value): ?string
    {
        return self::options($field)[$value] ?? null;
    }

    /**
     * A choice's values, in order.
     *
     * @param array<string, mixed> $field
     * @return list<string>
     */
    public static function values(array $field): array
    {
        return array_map(strval(...), array_keys(self::options($field)));
    }

    /**
     * A choice typed by its label or its value; null for none of them.
     *
     * @param array<string, mixed> $field
     */
    private static function pick(array $field, string $typed): ?string
    {
        $typed = trim(Persian::latinDigits($typed));
        foreach (self::options($field) as $value => $label) {
            if (in_array($typed, [(string) $value, trim($label)], true)) {
                return (string) $value;
            }
        }

        return null;
    }

    /** «بله» or «خیر» (on or off) typed; null for neither. */
    private static function yes(string $typed): ?bool
    {
        return match (mb_strtolower(trim($typed))) {
            'بله', 'آره', 'روشن', 'yes', 'on', '1' => true,
            'خیر', 'نه', 'خاموش', 'no', 'off', '0' => false,
            default => null,
        };
    }

    /**
     * A premium emoji sent: its id, and its plain emoji at the start of the name it is for (in place of the one there).
     *
     * @param array<string, mixed> $field
     * @param array<string, string|bool> $draft
     * @return array<string, string|bool>|null
     */
    private function emoji(array $field, Answer $answer, array $draft): ?array
    {
        $message = $answer->message();
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        foreach ((array) ($message['entities'] ?? $message['caption_entities'] ?? []) as $entity) {
            if (!is_array($entity) || ($entity['type'] ?? null) !== 'custom_emoji' || !is_string($entity['custom_emoji_id'] ?? null)) {
                continue;
            }
            $plain = self::utf16($text, (int) ($entity['offset'] ?? 0), (int) ($entity['length'] ?? 0));
            $for = (string) (((array) ($field['bound_to'] ?? []))[0] ?? 'name');
            $name = trim((string) ($draft[$for] ?? ''));
            $leading = PremiumText::leadingEmoji($name);
            $rest = trim($leading === null ? $name : substr($name, strlen($leading)));

            return [self::name($field) => $entity['custom_emoji_id'], $for => trim($plain . ' ' . $rest)];
        }

        return null;
    }

    /** The part of `$text` a Telegram entity marks: its offset and length are counted in UTF-16 code units. */
    private static function utf16(string $text, int $offset, int $length): string
    {
        $units = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return mb_convert_encoding(substr($units, $offset * 2, $length * 2), 'UTF-8', 'UTF-16LE');
    }

    /**
     * What identifies a field's value, as the API compares a bound secret's fields: a number its digits, an address its
     * origin, a proxy's host in lower case.
     *
     * @param array<string, mixed> $field
     */
    private static function identity(array $field, mixed $value): string
    {
        $text = trim(Persian::latinDigits(is_scalar($value) ? (string) $value : ''));

        return match (true) {
            self::name($field) === 'proxy_host' => trim(strtolower($text), '[]'),
            $field['type'] === 'number' => preg_replace('/\D/', '', $text) ?? '',
            $field['type'] === 'url' => self::origin($text),
            default => $text,
        };
    }

    /** An address's scheme, host and port, in lower case. */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return strtolower($url);
        }

        return strtolower(($parts['scheme'] ?? '') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    }
}
