<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui\Settings;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;

/**
 * Where a settings card's values live in the panel's API, and how one changed field is saved there: a bot settings
 * group (BotGroupSource, saved whole), a record patched a field at a time (PatchSource), or one put back whole
 * (PutSource). A card of another shape writes its own.
 */
interface SettingsSource
{
    /**
     * The card's values, by the API's field names.
     *
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    public function load(Admin $admin): array;

    /**
     * Save `$field` as `$value` — `$values` the card's values before —, and the values as the panel has them after.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     * @throws ApiRefusal 422 under the field's name when the panel refuses the value
     */
    public function save(Admin $admin, array $values, string $field, mixed $value): array;
}
