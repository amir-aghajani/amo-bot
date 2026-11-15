<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui\Settings;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;

/**
 * A record the panel patches a field at a time (the website, the mini app): read from GET `$path` (its values under
 * `$key`, or the answer itself without one), saved by PATCH `$path` with the one field.
 */
final class PatchSource implements SettingsSource
{
    public function __construct(
        private readonly string $path,
        private readonly ?string $key = null,
    ) {}

    public function load(Admin $admin): array
    {
        return $this->values($admin->api->get($this->path));
    }

    public function save(Admin $admin, array $values, string $field, mixed $value): array
    {
        return $this->values($admin->api->patch($this->path, [$field => $value]));
    }

    /**
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function values(array $answer): array
    {
        return $this->key === null ? $answer : Json::part($answer, $this->key);
    }
}
