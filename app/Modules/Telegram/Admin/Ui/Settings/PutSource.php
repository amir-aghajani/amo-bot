<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui\Settings;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;

/**
 * A record the panel saves whole (the agency's settings, the schedule): read from GET `$path` (its values under
 * `$key`, or the answer itself), saved by PUT `$putPath` (the same path by default) with every value and the one
 * changed; `$only` limits what is sent to the fields the PUT takes, when the GET answers more.
 */
final class PutSource implements SettingsSource
{
    /**
     * @param list<string> $only
     */
    public function __construct(
        private readonly string $path,
        private readonly ?string $key = null,
        private readonly ?string $putPath = null,
        private readonly array $only = [],
    ) {}

    public function load(Admin $admin): array
    {
        return $this->values($admin->api->get($this->path));
    }

    public function save(Admin $admin, array $values, string $field, mixed $value): array
    {
        $body = array_replace($values, [$field => $value]);
        if ($this->only !== []) {
            $body = array_intersect_key($body, array_flip($this->only));
        }

        return $this->values($admin->api->put($this->putPath ?? $this->path, $body));
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
