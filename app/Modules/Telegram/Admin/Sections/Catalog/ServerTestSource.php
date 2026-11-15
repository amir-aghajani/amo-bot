<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;

/**
 * A server's test terms (GET/PUT /servers/{id}/test-account, under `test_account`), saved whole as the panel's card
 * saves them; the server is the one the card's screen keeps in the chat (`id` in `$screen`'s state).
 */
final class ServerTestSource implements SettingsSource
{
    /** What the route takes. */
    private const FIELDS = ['test_enabled', 'test_traffic_mb', 'test_duration_hours', 'test_on_hold'];

    public function __construct(private readonly string $screen) {}

    public function load(Admin $admin): array
    {
        return $this->values($admin->api->get($this->path($admin)));
    }

    public function save(Admin $admin, array $values, string $field, mixed $value): array
    {
        $body = array_intersect_key(array_replace($values, [$field => $value]), array_flip(self::FIELDS));

        return $this->values($admin->api->put($this->path($admin), $body));
    }

    private function path(Admin $admin): string
    {
        return '/servers/' . (int) ($admin->state($this->screen)['id'] ?? 0) . '/test-account';
    }

    /**
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function values(array $answer): array
    {
        return Json::part($answer, 'test_account');
    }
}
