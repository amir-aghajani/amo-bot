<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\Input;
use App\Support\Validation;

/**
 * A line of text, trimmed — or a file's path, or a few lines (`type`): at most `max` characters, required or not, and —
 * when it has a `pattern` — of that shape (`mismatch` says which). `normalize` tidies what was typed before it is
 * checked (a leading "@" off a username).
 *
 * @extends Field<string>
 */
final class Text extends Field
{
    /** @param (\Closure(string): string)|null $normalize */
    public function __construct(
        string $name,
        string $key,
        string $default,
        /** What the messages call it */
        public readonly string $label,
        private readonly int $max,
        private readonly bool $required = false,
        private readonly ?string $pattern = null,
        private readonly string $mismatch = '',
        private readonly ?\Closure $normalize = null,
        private readonly FieldType $type = FieldType::Text,
        ?FieldSpec $spec = null,
    ) {
        if (!in_array($type, [FieldType::Text, FieldType::Path, FieldType::Textarea], true)) {
            throw new \LogicException("The text \"{$name}\" holds text, a path or lines — not a {$type->value}.");
        }
        parent::__construct($name, $key, $default, $spec);
    }

    public function type(): FieldType
    {
        return $this->type;
    }

    public function required(): bool
    {
        return $this->required;
    }

    public function read(array $input, mixed $kept): string
    {
        $value = Input::text($input, $this->name);
        if ($this->normalize !== null) {
            $value = ($this->normalize)($value);
        }

        if ($value === '' && $this->required) {
            throw new FieldRefused("{$this->label} را وارد کنید.");
        }
        if ($value === '') {
            return '';
        }
        if (mb_strlen($value) > $this->max) {
            throw new FieldRefused(Validation::tooLong($this->label, $this->max));
        }
        if ($this->pattern !== null && preg_match($this->pattern, $value) !== 1) {
            throw new FieldRefused($this->mismatch);
        }

        return $value;
    }

    public function cast(mixed $kept): string
    {
        return is_scalar($kept) ? trim((string) $kept) : $this->default;
    }
}
