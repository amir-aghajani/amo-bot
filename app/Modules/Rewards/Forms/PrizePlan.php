<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Forms;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Modules\Catalog\Models\Plan;
use App\Support\Input;

/**
 * A plan of the current shop given as a prize (switched on or not: a prize is no sale), kept by its id. Required, or —
 * with `$neededWhen` — optional (blank or 0 is none) and asked for only while that number is above 0. A plan deleted
 * since reads back as kept; whoever gives the prize then finds it gone (Reward::of()).
 *
 * @extends Field<int|null>
 */
final class PrizePlan extends Field
{
    public const PICK = 'پلن جایزه را انتخاب کنید.';
    public const NOT_A_PLAN = 'این پلن در فروشگاه نیست.';

    public function __construct(string $name, string $key, ?FieldSpec $spec = null, private readonly ?Number $neededWhen = null)
    {
        parent::__construct($name, $key, null, $spec);
    }

    public function type(): FieldType
    {
        return FieldType::Number;
    }

    public function required(): bool
    {
        return $this->neededWhen === null;
    }

    public function read(array $input, mixed $kept): ?int
    {
        $value = $input[$this->name] ?? null;
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return $this->required() ? throw new FieldRefused(self::PICK) : null;
        }

        $id = Input::integerOf($value);
        // BelongsToBot: another shop's plan is no plan of this one.
        if ($id === null || $id < 1 || !Plan::query()->whereKey($id)->exists()) {
            throw new FieldRefused(self::NOT_A_PLAN);
        }

        return $id;
    }

    public function cast(mixed $kept): ?int
    {
        $id = Input::integerOf($kept);

        return $id !== null && $id > 0 ? $id : null;
    }

    public function conflict(array $values): ?string
    {
        if ($this->neededWhen === null) {
            return null;
        }
        $needed = $values[$this->neededWhen->name] ?? 0;

        return is_int($needed) && $needed > 0 && ($values[$this->name] ?? null) === null ? self::PICK : null;
    }
}
