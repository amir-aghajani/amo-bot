<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Forms;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\FieldType;
use App\Modules\Subscriptions\DTO\ClientNamePattern;
use App\Support\Input;

/**
 * A pattern clients are named by (DTO\ClientNamePattern): refused as its problem() says, blank for the default. What is
 * kept is read back as the default unless it still holds, so a pattern a later release would refuse names nothing.
 *
 * @extends Field<string>
 */
final class NamePattern extends Field
{
    public function __construct(string $name, string $key, string $default)
    {
        parent::__construct($name, $key, $default);
    }

    public function type(): FieldType
    {
        return FieldType::Text;
    }

    public function read(array $input, mixed $kept): string
    {
        $pattern = Input::text($input, $this->name);
        if ($pattern === '') {
            return $this->default;
        }
        $problem = ClientNamePattern::problem($pattern);
        if ($problem !== null) {
            throw new FieldRefused($problem);
        }

        return $pattern;
    }

    public function cast(mixed $kept): string
    {
        return is_string($kept) && ClientNamePattern::problem($kept) === null ? $kept : $this->default;
    }
}
