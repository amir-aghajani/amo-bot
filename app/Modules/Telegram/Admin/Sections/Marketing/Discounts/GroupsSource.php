<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Discounts;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;

/**
 * A card of several bot settings groups (the panel's «تنظیمات تخفیف‌ها»: `discounts` and `agent_perks`): read from
 * GET /bot/settings, each field saved with its own group, whole (BotGroupSource).
 */
final class GroupsSource implements SettingsSource
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        private readonly array $groups,
        private readonly BotSettingsScreen $screen,
    ) {}

    public function load(Admin $admin): array
    {
        return (new BotGroupSource($this->groups[0], $this->screen))->load($admin);
    }

    public function save(Admin $admin, array $values, string $field, mixed $value): array
    {
        foreach ($this->groups as $group) {
            if (in_array($field, $this->screen->names($group), true)) {
                return (new BotGroupSource($group, $this->screen))->save($admin, $values, $field, $value);
            }
        }

        throw new \LogicException("No group of the card has the field {$field}.");
    }
}
