<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Core\Config\ConfigFile;
use App\Core\Config\ConfigValues;
use App\Core\Config\Repository as Config;
use App\Core\Database\Drivers\DatabaseDriver;
use App\Core\Database\Drivers\ProbeFailedException;
use App\Core\Database\Drivers\ProbeResult;
use App\Core\Drivers\Registry;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Form;
use App\Support\Input;

/**
 * The database part of config.php, for the settings screen (ConfigSettings' `database` group) and the web installer
 * alike: the driver DB_CONNECTION names and its form, which drivers the admin may pick, the admin's choice checked by
 * the chosen driver's form — a password left blank kept only while the address it was given for stays (its secret's
 * `boundTo`) —, and whether a database answers to it. Everything about one database is its driver's
 * (App\Core\Database\Drivers).
 */
final class DatabaseSettings
{
    /** @param Registry<DatabaseDriver> $drivers */
    public function __construct(
        private readonly ConfigFile $file,
        private readonly Config $config,
        private readonly Registry $drivers,
    ) {}

    /**
     * The group as a screen shows it: the driver, the drivers on offer (each described: its form's fields), and the
     * driver's fields as config.php holds them — a password as whether one is kept, never a character or the length of it.
     *
     * @return array{driver: string, drivers: list<array<string, mixed>>, values: array<string, mixed>}
     */
    public function present(): array
    {
        $driver = $this->current();
        $config = $this->configOf($driver);

        return [
            'driver' => $driver->key(),
            'drivers' => array_map(static fn(DatabaseDriver $offered): array => $offered->describe()->toArray(), $this->offered($driver->key())),
            'values' => $driver->describe()->form->present(static fn(Field $field): mixed => $config[$field->key] ?? null),
        ];
    }

    /**
     * The driver the admin picked (`driver`): one the screen offers; none named is the one it shows.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function chosen(array $input): DatabaseDriver
    {
        $current = $this->current();
        $key = Input::text($input, 'driver');
        $key = $key !== '' ? $key : $current->key();
        foreach ($this->offered($current->key()) as $driver) {
            if ($driver->key() === $key) {
                return $driver;
            }
        }

        throw ValidationException::on('driver', 'این نوع دیتابیس را نمی‌شود انتخاب کرد.');
    }

    /**
     * The admin's choice checked by the chosen driver's form: DB_CONNECTION and the driver's settings, ready to write —
     * once probe() says yes. A secret left blank keeps what is stored only for the driver whose values the screen showed
     * (another one starts with nothing stored), and only while its address stays — moved, as the driver reads it (so
     * «۳۳۰۷» is still 3307), it is asked for again: a password kept is never sent to another server, a test of the new
     * address included.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        $driver = $this->chosen($input);

        return ['DB_CONNECTION' => $driver->key()] + $driver->describe()->form->check($input, Form::keptFor($driver->key(), $this->current()->key(), $this->configOf($driver)));
    }

    /**
     * Whether a database answers to `$config` — validate()'s settings, or config.php's as they are — and what it is.
     *
     * @param array<string, mixed> $config
     * @throws ProbeFailedException
     */
    public function probe(array $config): ProbeResult
    {
        $key = is_string($config['DB_CONNECTION'] ?? null) ? $config['DB_CONNECTION'] : '';
        if (!$this->drivers->has($key)) {
            throw new ProbeFailedException("DB_CONNECTION={$key} نوع دیتابیسی نیست که فروشگاه بشناسد.");
        }

        $driver = $this->drivers->get($key);
        foreach ($driver->extensions() as $extension) {
            if (!extension_loaded($extension)) {
                throw ProbeFailedException::missingExtension($extension);
            }
        }

        return $driver->probe($config);
    }

    /**
     * What a screen lets the admin pick, in registration order: the drivers the installer offers, and `$current` — the
     * one config.php names or the shop runs on — even when it is not one of them.
     *
     * @return list<DatabaseDriver>
     */
    private function offered(string $current): array
    {
        return array_values(array_filter(
            $this->drivers->all(),
            static fn(DatabaseDriver $driver): bool => ($driver->describe()->traits[DatabaseDriver::INSTALLABLE] ?? false) === true || $driver->key() === $current,
        ));
    }

    /**
     * The driver a screen starts from: the one config.php names, else the one the shop runs on — or, when that is no
     * driver at all, the first the screens offer.
     */
    private function current(): DatabaseDriver
    {
        $key = $this->stored()['DB_CONNECTION'] ?? $this->running();

        return $this->drivers->find($key) ?? $this->offered($key)[0] ?? throw new \LogicException('No database driver is offered.');
    }

    /**
     * What `$driver`'s fields hold: config.php's DB_* settings, else — for the driver the shop runs on — what it runs
     * with (the ones it booted with), else the fields' defaults.
     *
     * @return array<string, string>
     */
    private function configOf(DatabaseDriver $driver): array
    {
        $stored = $this->stored();

        return $driver->key() === $this->running() ? $stored + (array) $this->config->get('database.connection', []) : $stored;
    }

    /** @return array<string, string> config.php's DB_* settings as they are now, as text */
    private function stored(): array
    {
        return (new ConfigValues($this->file->all()))->prefixed('DB_');
    }

    private function running(): string
    {
        return (string) $this->config->get('database.driver', 'mysql');
    }
}
