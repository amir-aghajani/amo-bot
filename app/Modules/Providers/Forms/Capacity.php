<?php

declare(strict_types=1);

namespace App\Modules\Providers\Forms;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\Input;

/**
 * How many running services a server takes: blank — no limit —, or a whole number (Persian digits too), 0 a full server
 * nothing more is sold on.
 *
 * @extends Field<int|null>
 */
final class Capacity extends Field
{
    public function __construct(string $name, string $key, ?FieldSpec $spec = null)
    {
        parent::__construct($name, $key, null, $spec);
    }

    public function type(): FieldType
    {
        return FieldType::Number;
    }

    public function read(array $input, mixed $kept): ?int
    {
        if (Input::text($input, $this->name) === '') {
            return null;
        }

        return Input::integer($input, $this->name) ?? throw new FieldRefused('ظرفیت باید یک عدد صفر یا بزرگ‌تر باشد؛ برای نامحدود خالی بگذارید.');
    }

    public function cast(mixed $kept): ?int
    {
        return Input::integerOf($kept);
    }

    /** No limit as a form holds it: blank. */
    public function present(mixed $value): int|string
    {
        return $value ?? '';
    }

    protected function bounds(): array
    {
        return [0, null];
    }
}
