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
 * The database part of config.php, for the settings screen and the installer: the driver DB_CONNECTION names and its
 * form, the drivers offered, the admin's choice checked by the chosen driver's form, and the probe.
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
     * The group as a screen shows it: the driver, the drivers on offer (described), and the driver's values (a password
     * only as whether one is kept).
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
     * The driver picked (`driver`), one the screen offers; none named is the one it shows.
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
     * Check the choice by the chosen driver's form: DB_CONNECTION and its settings, to write once probe() passes. A blank
     * password keeps the stored one only for the driver shown and only while its address stays — a kept password never
     * goes to another server, not even for a test.
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
     * Probe a database with `$config` (validate()'s settings, or config.php's): what answered.
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
     * The drivers a screen offers: the installable ones, and `$current` even when it is not.
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

    /** The driver a screen starts from: config.php's, else the running one, else the first offered. */
    private function current(): DatabaseDriver
    {
        $key = $this->stored()['DB_CONNECTION'] ?? $this->running();

        return $this->drivers->find($key) ?? $this->offered($key)[0] ?? throw new \LogicException('No database driver is offered.');
    }

    /**
     * What `$driver`'s fields hold: config.php's DB_* settings, plus — for the running driver — what it booted with.
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
