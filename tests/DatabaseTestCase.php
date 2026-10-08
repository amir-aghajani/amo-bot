<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database\ChangeFeed;
use App\Core\Database\Schema;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Settings\Services\Settings;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Tests\Support\Fixtures;

/**
 * Makes the tables once per process and wraps every test in a transaction that is rolled back; the
 * Settings cache is dropped with it so one test's settings never leak into the next. The rows a
 * scenario needs come from the Fixtures. The app's event listeners — the change feed's, every model's
 * own hooks (booted once, up front) — are as each test found them when it ends: a test that left them
 * changed fails, and the next one starts with them as they were.
 */
abstract class DatabaseTestCase extends TestCase
{
    use Fixtures;

    private static bool $tablesMade = false;

    /** @var array<string, array<int, mixed>> The event listeners as this test found them */
    private array $listeners = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$tablesMade) {
            $this->service(Schema::class)->create();
            self::bootModels();
            self::$tablesMade = true;
        }

        $this->listeners = $this->events()->getRawListeners();
        $this->db()->beginTransaction();
        // For the panel's change feed, the test's transaction is the database: a write in it is a committed one.
        $this->service(ChangeFeed::class)->countAsCommitted($this->db()->transactionLevel());
    }

    protected function tearDown(): void
    {
        $changed = $this->putListenersBack();
        $this->db()->rollBack();
        $this->service(ChangeFeed::class)->countAsCommitted(0);
        $this->service(Settings::class)->refresh();

        parent::tearDown();

        if ($changed !== []) {
            self::fail('The test left the listeners of ' . implode(', ', $changed) . ' changed (put back for the next test): hear an event through whileListening().');
        }
    }

    protected function db(): Connection
    {
        return $this->service(Connection::class);
    }

    /**
     * `$work` done while `$listener` hears `$event` as well — a model's (`'eloquent.creating: ' . CustomerGroup::class`)
     * or the connection's (`QueryExecuted::class`): another process's move arranged at the moment it matters. The event's
     * own listeners are as they were afterwards: never `forget()` one by hand — that drops the app's too, which a model
     * registers once a process (BelongsToBot's creating hook), and every later test would run without it.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    protected function whileListening(string $event, \Closure $listener, \Closure $work): mixed
    {
        $events = $this->events();
        $before = $events->getRawListeners()[$event] ?? [];
        $events->listen($event, $listener);

        try {
            return $work();
        } finally {
            $events->forget($event);
            foreach ($before as $kept) {
                $events->listen($event, $kept);
            }
        }
    }

    /**
     * `$work` done while support cancels the order — on the orders screen, in another process — the moment `$work` first
     * reads one: what it read says open, the row is cancelled. The race a payment's settlement must lose cleanly.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    protected function whileTheOrderClosesOnceRead(\Closure $work): mixed
    {
        $closed = false;

        return $this->whileListening('eloquent.retrieved: ' . Order::class, static function (Order $read) use (&$closed): void {
            if (!$closed) {
                $closed = true;
                Order::query()->whereKey($read->id)->update(['status' => OrderStatus::Cancelled->value]);
            }
        }, $work);
    }

    /** The events the app's models and its connection go through (one dispatcher for both). */
    private function events(): Dispatcher
    {
        $events = $this->db()->getEventDispatcher();
        assert($events instanceof Dispatcher);

        return $events;
    }

    /** @return list<string> The events whose listeners the test left changed — each put back as the test found it. */
    private function putListenersBack(): array
    {
        $events = $this->events();
        $now = $events->getRawListeners();
        $changed = [];
        foreach (array_unique([...array_keys($now), ...array_keys($this->listeners)]) as $event) {
            if (($now[$event] ?? []) === ($this->listeners[$event] ?? [])) {
                continue;
            }
            $changed[] = $event;
            $events->forget($event);
            foreach ($this->listeners[$event] ?? [] as $listener) {
                $events->listen($event, $listener);
            }
        }

        return $changed;
    }

    /**
     * Every model of the app booted once a process — its own hooks registered (BelongsToBot's) — so the first test to use a
     * model changes no listener.
     */
    private static function bootModels(): void
    {
        foreach (glob(dirname(__DIR__) . '/app/Modules/*/Models/*.php') ?: [] as $file) {
            $class = 'App\\Modules\\' . basename(dirname($file, 2)) . '\\Models\\' . basename($file, '.php');
            if (is_subclass_of($class, Model::class) && !(new \ReflectionClass($class))->isAbstract()) {
                new $class();
            }
        }
    }
}
