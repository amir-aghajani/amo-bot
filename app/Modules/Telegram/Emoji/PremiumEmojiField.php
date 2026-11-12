<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Emoji;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Modules\Telegram\Texts\PremiumText;
use App\Support\Input;

/**
 * The premium emoji the bot draws for a name's leading emoji (Telegram\Texts\PremiumText) — a server's —, chosen from
 * the bot's list: a custom emoji's id, blank for none. The name it is for (`$for`, a field of the same form) must start
 * with an emoji while one is chosen; a generic form draws the picker beside it (`bound_to`), which puts the chosen
 * one's plain emoji at the name's start.
 *
 * @extends Field<string>
 */
final class PremiumEmojiField extends Field
{
    public function __construct(
        string $name,
        string $key,
        /** The name field it is for */
        private readonly string $for,
        ?FieldSpec $spec = null,
    ) {
        parent::__construct($name, $key, '', $spec);
    }

    public function type(): FieldType
    {
        return FieldType::PremiumEmoji;
    }

    public function read(array $input, mixed $kept): string
    {
        $id = Input::text($input, $this->name);
        if ($id !== '' && preg_match('/^\d{1,20}$/D', $id) !== 1) {
            throw new FieldRefused(PremiumText::ID_REFUSED);
        }

        return $id;
    }

    public function cast(mixed $kept): string
    {
        return is_string($kept) && preg_match('/^\d{1,20}$/D', $kept) === 1 ? $kept : $this->default;
    }

    public function conflict(array $values): ?string
    {
        $id = $values[$this->name] ?? '';

        return $id !== '' && PremiumText::leadingEmoji((string) ($values[$this->for] ?? '')) === null ? PremiumText::NO_EMOJI : null;
    }

    public function describe(): array
    {
        $described = parent::describe();
        $described['bound_to'] = [$this->for];

        return $described;
    }
}
