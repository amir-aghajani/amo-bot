<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Config\Repository as Config;
use App\Core\Database\Drivers\DatabaseDriver;
use App\Core\Drivers\Registry;
use App\Core\Drivers\UnknownDriverException;
use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * The shop's database: the driver DB_CONNECTION names (config/database.php), its connection booted into Eloquent
 * (illuminate/database) outside of Laravel. The connection is lazy: nothing talks to the database until the first query.
 */
final class DatabaseManager
{
    private ?DatabaseDriver $driver = null;

    /** @param Registry<DatabaseDriver> $drivers The databases the shop can run on, by what DB_CONNECTION holds */
    public function __construct(
        private readonly Config $config,
        private readonly Capsule $capsule,
        private readonly Registry $drivers,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Hand Eloquent the driver's connection, once, at boot (Application). A relation read row by row off a list — a
     * query per row, where one eager load does — is a mistake the test suite fails on (it throws there); live it is
     * logged once a process for each relation, and the rows still load: nobody's answer waits on it.
     *
     * @throws UnknownDriverException when DB_CONNECTION names no driver: nothing runs without its database
     */
    public function boot(): void
    {
        $driver = $this->driver();
        $this->capsule->addConnection($driver->connection((array) $this->config->get('database.connection', [])), $driver->key());
        $this->capsule->getDatabaseManager()->setDefaultConnection($driver->key());
        $this->capsule->setEventDispatcher(new EventDispatcher(new IlluminateContainer()));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $logged = [];
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) use (&$logged): void {
            $key = $model::class . '::' . $relation;
            if (!isset($logged[$key])) {
                $logged[$key] = true;
                $this->logger->warning('{relation} is read row by row off a list: eager load it with the list.', ['relation' => $key]);
            }
        });
    }

    /**
     * The driver the shop runs on: what the dashboard's day, the schema tooling and the requirements ask.
     *
     * @throws UnknownDriverException when DB_CONNECTION names no driver
     */
    public function driver(): DatabaseDriver
    {
        if ($this->driver === null) {
            $key = (string) $this->config->get('database.driver', 'mysql');
            $known = array_map(static fn(DatabaseDriver $driver): string => $driver->key(), $this->drivers->all());
            $this->driver = $this->drivers->find($key) ?? throw new UnknownDriverException(sprintf('DB_CONNECTION "%s" names no database driver (%s).', $key, implode(', ', $known)));
        }

        return $this->driver;
    }

    /** Whether the database answers a query now; why not goes to the log, never to the caller (it names the host and the user). */
    public function answers(): bool
    {
        try {
            $this->capsule->getConnection()->select('select 1');

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('The database does not answer: {message}', ['message' => $e->getMessage()]);

            return false;
        }
    }
}
