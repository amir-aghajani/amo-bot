<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Manual;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\BankCard;
use App\Support\Input;

/**
 * An Iranian bank card's number (App\Support\BankCard): typed as it is printed — Persian digits, spaces or dashes
 * between them — and kept as its 16 bare digits, which must pass the check digit every bank's card carries. A kept one
 * that would not is none.
 *
 * @extends Field<string>
 */
final class CardNumber extends Field
{
    public function __construct(
        string $name,
        string $key,
        /** What the messages call it */
        public readonly string $label,
        ?FieldSpec $spec = null,
    ) {
        parent::__construct($name, $key, '', $spec);
    }

    public function type(): FieldType
    {
        return FieldType::Card;
    }

    public function required(): bool
    {
        return true;
    }

    public function read(array $input, mixed $kept): string
    {
        $card = BankCard::normalize(Input::text($input, $this->name));
        if ($card === '') {
            throw new FieldRefused("{$this->label} را وارد کنید.");
        }
        if (!BankCard::isValid($card)) {
            throw new FieldRefused("{$this->label} باید 16 رقم و معتبر باشد.");
        }

        return $card;
    }

    public function cast(mixed $kept): string
    {
        $card = is_string($kept) ? BankCard::normalize($kept) : '';

        return BankCard::isValid($card) ? $card : $this->default;
    }
}
