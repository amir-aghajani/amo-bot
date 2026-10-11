<?php

declare(strict_types=1);

namespace App\Modules\Providers;

use App\Core\Drivers\Registry;
use App\Core\Drivers\UnknownDriverException;
use App\Modules\Providers\Contracts\PanelDriver;
use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelHttp;

/**
 * The panel connectors by key (`servers.driver`; registered from `panel.drivers` in bootstrap/container.php), and the
 * client of each server's panel. A server's client is reused for KEEP_SECONDS while its connection stays the same, so
 * the session or token it signed in for, and the panel settings it read, serve the calls that follow — one sign-in per
 * process, not per call. A changed connection gets a fresh client at once, and a long-running process (bot:poll)
 * re-reads a panel's settings at least that often.
 */
final class ProviderRegistry
{
    /** How long a server's client is reused. */
    private const KEEP_SECONDS = 300;

    /** @var Registry<PanelDriver> */
    private Registry $drivers;

    /** @var array<int, array{connection: string, until: int, provider: ProviderInterface}> By server id */
    private array $connected = [];

    public function __construct(private readonly PanelHttp $http)
    {
        $this->drivers = new Registry([]);
    }

    /**
     * Add a connector: each of the installation's as the registry is built, or a test's fake panel.
     *
     * @throws \LogicException for a key another connector has
     */
    public function register(PanelDriver $driver): void
    {
        $this->drivers = new Registry([...$this->drivers->all(), $driver]);
    }

    /** Forget a connector and the clients made — a test's fake panel, removed before the next test. */
    public function unregister(string $driver): void
    {
        $this->drivers = new Registry(array_filter($this->drivers->all(), static fn(PanelDriver $registered): bool => $registered->key() !== $driver));
        $this->flush();
    }

    /** Forget every client kept, so the next call for a server builds one afresh (between tests). */
    public function flush(): void
    {
        $this->connected = [];
    }

    public function has(string $driver): bool
    {
        return $this->drivers->has($driver);
    }

    /** @return list<PanelDriver> Every connector, in the order they were registered: the add-server picker's. */
    public function all(): array
    {
        return $this->drivers->all();
    }

    /** The connector of this key; null for one this installation does not have (a server left from another). */
    public function find(string $driver): ?PanelDriver
    {
        return $this->drivers->find($driver);
    }

    /** @throws UnknownDriverException for a key no connector has */
    public function label(string $driver): string
    {
        return $this->drivers->get($driver)->describe()->label;
    }

    /**
     * What a connector's panels can do; nothing for a key no connector is registered under (such a server is sold
     * nowhere and offers nothing).
     */
    public function capabilities(string $driver): Capabilities
    {
        return $this->drivers->find($driver)?->capabilities() ?? new Capabilities();
    }

    /**
     * The client of the server's panel, reused while its connection is unchanged. A server not stored yet (a probe of the
     * form) gets one of its own.
     *
     * @throws UnknownDriverException for a key no connector has
     */
    public function forServer(Server $server): ProviderInterface
    {
        $driver = $this->drivers->get($server->driver);
        if (!$server->exists) {
            return $driver->connect($server, $this->http);
        }

        $connection = self::fingerprint($server);
        $now = now()->getTimestamp();
        $kept = $this->connected[$server->id] ?? null;
        if ($kept !== null && $kept['connection'] === $connection && $kept['until'] > $now) {
            return $kept['provider'];
        }

        $provider = $driver->connect($server, $this->http);
        $this->connected[$server->id] = ['connection' => $connection, 'until' => $now + self::KEEP_SECONDS, 'provider' => $provider];

        return $provider;
    }

    /** Everything a client is built from: a change to any of it is another connection. */
    private static function fingerprint(Server $server): string
    {
        return hash('sha256', (string) json_encode([
            $server->driver,
            $server->base_url,
            $server->api_token,
            $server->username,
            $server->password,
            $server->totp_secret,
            $server->meta,
        ]));
    }
}
