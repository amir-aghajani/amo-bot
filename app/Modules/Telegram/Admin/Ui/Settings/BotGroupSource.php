<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui\Settings;

use App\Modules\Bots\CurrentBot;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;

/**
 * A bot settings group («تنظیمات ربات»'s cards): read from GET /bot/settings — every group's fields by name —, saved by
 * PUT /bot/settings/{group} with the whole group as it stands and the one field changed (the panel saves a card
 * whole, and so does this) — the group's fields as the seat's shop has them, which the owner may work from another
 * shop's bot.
 */
final class BotGroupSource implements SettingsSource
{
    public function __construct(
        private readonly string $group,
        private readonly BotSettingsScreen $screen,
    ) {}

    public function load(Admin $admin): array
    {
        return self::settings($admin);
    }

    /**
     * Every bot settings group's fields by name, as the seat's shop has them (GET /bot/settings).
     *
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    public static function settings(Admin $admin): array
    {
        return Json::part($admin->api->get('/bot/settings'), 'settings');
    }

    public function save(Admin $admin, array $values, string $field, mixed $value): array
    {
        $body = [];
        foreach (CurrentBot::run($admin->seat->shop(), fn(): array => $this->screen->names($this->group)) as $name) {
            $body[$name] = $name === $field ? $value : ($values[$name] ?? null);
        }
        return Json::part($admin->api->put('/bot/settings/' . $this->group, $body), 'settings');
    }
}
