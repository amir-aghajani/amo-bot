<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Form;
use App\Modules\Bots\CurrentBot;
use App\Modules\Settings\Models\Setting;

/**
 * Runtime settings kept as JSON in the `settings` table, each bot its own (the current bot unless `$bot` says). A key
 * nobody wrote reads as the caller's default. A process reads a bot's rows once; a long-lived one (bot:poll) calls
 * refresh() to see a panel's changes. Forms are read (read(), present()) and saved whole or not at all (save()).
 */
final class Settings
{
    /** @var array<int, array<string, mixed>> Each bot's rows, read once */
    private array $cache = [];

    public function get(string $key, mixed $default = null, ?int $bot = null): mixed
    {
        $rows = $this->load($bot ?? CurrentBot::id());

        return array_key_exists($key, $rows) ? $rows[$key] : $default;
    }

    /**
     * A field's value, its default while nothing is kept.
     *
     * @template T
     * @param Field<T> $field
     * @return T
     */
    public function read(Field $field, ?int $bot = null): mixed
    {
        return $field->cast($this->get($field->key, null, $bot));
    }

    /** @return array<string, mixed> The group's fields as its screen shows them, by name */
    public function present(Form $group, ?int $bot = null): array
    {
        return $group->present(fn(Field $field): mixed => $this->get($field->key, null, $bot));
    }

    /**
     * Check the group's form and keep every field of it, or nothing.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function save(Form $group, array $input, ?int $bot = null): void
    {
        $values = $group->check($input, fn(Field $field): mixed => $this->get($field->key, null, $bot));

        try {
            (new Setting())->getConnection()->transaction(function () use ($values, $bot): void {
                foreach ($values as $key => $value) {
                    $this->set($key, $value, bot: $bot);
                }
            });
        } catch (\Throwable $e) {
            // What the cache took from the rolled-back writes is not what the table has.
            unset($this->cache[$bot ?? CurrentBot::id()]);

            throw $e;
        }
    }

    public function set(string $key, mixed $value, ?int $bot = null): void
    {
        $bot ??= CurrentBot::id();
        // Populate first: writing one key into an empty cache would make that key the whole cache.
        $this->load($bot);

        Setting::query()->updateOrCreate(['bot_id' => $bot, 'key' => $key], ['value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);

        $this->cache[$bot][$key] = $value;
    }

    public function forget(string $key): void
    {
        $bot = CurrentBot::id();
        $this->load($bot);
        Setting::query()->where('bot_id', $bot)->where('key', $key)->delete();
        unset($this->cache[$bot][$key]);
    }

    public function refresh(): void
    {
        $this->cache = [];
    }

    /** @return array<string, mixed> The bot's rows, decoded */
    private function load(int $bot): array
    {
        if (!isset($this->cache[$bot])) {
            $this->cache[$bot] = [];
            foreach (Setting::query()->where('bot_id', $bot)->get() as $row) {
                $this->cache[$bot][$row->key] = $row->value === null ? null : json_decode($row->value, true);
            }
        }

        return $this->cache[$bot];
    }
}
