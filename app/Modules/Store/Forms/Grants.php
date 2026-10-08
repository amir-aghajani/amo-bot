<?php

declare(strict_types=1);

namespace App\Modules\Store\Forms;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\FieldType;
use App\Modules\Store\Enums\StaffGrant;

/**
 * What the website lets the shop's admins do beyond the shop's daily work: a list of grants (Enums\StaffGrant), the whole
 * of it sent — what is not in it is not granted —, kept without repeats in the grants' own order. Anything that is no
 * grant is refused; a kept value reads back with what is no grant any more left out. The website's card draws it, one
 * switch a grant.
 *
 * @extends Field<list<string>>
 */
final class Grants extends Field
{
    private const REFUSED = 'دسترسی‌ها را از گزینه‌ها انتخاب کنید.';

    public function __construct(string $name, string $key)
    {
        parent::__construct($name, $key, []);
    }

    public function type(): FieldType
    {
        return FieldType::List;
    }

    public function read(array $input, mixed $kept): array
    {
        $sent = $input[$this->name] ?? null;
        if (!is_array($sent) || !array_is_list($sent)) {
            throw new FieldRefused(self::REFUSED);
        }
        foreach ($sent as $grant) {
            if (!is_string($grant) || StaffGrant::tryFrom($grant) === null) {
                throw new FieldRefused(self::REFUSED);
            }
        }

        return StaffGrant::among($sent);
    }

    public function cast(mixed $kept): array
    {
        return is_array($kept) ? StaffGrant::among($kept) : $this->default;
    }
}
