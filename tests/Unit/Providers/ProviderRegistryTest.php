<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Core\Drivers\UnknownDriverException;
use App\Modules\Providers\Drivers\ThreeXui\ThreeXuiDriver;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Providers\Support\PanelHttp;
use Illuminate\Support\Carbon;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * A server's client is kept and handed out again while its connection stays the same — the session it signed in for
 * serves the calls that follow — and built afresh by its connector once anything it was built from changes, or after a
 * while; a connector is registered once.
 */
final class ProviderRegistryTest extends TestCase
{
    public function testAStoredServersDriverIsHandedOutAgainWhileItsConnectionStaysTheSame(): void
    {
        $registry = $this->registry();
        $server = self::stored(['api_token' => 'tok']);

        $first = $registry->forServer($server);
        self::assertSame($first, $registry->forServer($server));
        self::assertSame($first, $registry->forServer(self::stored(['api_token' => 'tok'])), 'the same row read again');
    }

    public function testAnythingTheDriverIsBuiltFromChangedIsAnotherConnection(): void
    {
        $registry = $this->registry();
        $first = $registry->forServer(self::stored(['api_token' => 'tok']));

        foreach ([
            'another token' => ['api_token' => 'rotated'],
            'another address' => ['api_token' => 'tok', 'base_url' => 'https://de2.example.com:2053/AbCdEf'],
            'other options' => ['api_token' => 'tok', 'meta' => ['verify_tls' => false]],
            'a password instead' => ['api_token' => null, 'username' => 'admin', 'password' => 'pw'],
        ] as $case => $attributes) {
            self::assertNotSame($first, $registry->forServer(self::stored($attributes)), $case);
        }
    }

    public function testADriverIsBuiltAfreshAfterAWhileAndOnRequest(): void
    {
        $registry = $this->registry();
        $server = self::stored(['api_token' => 'tok']);

        Carbon::setTestNow('2026-10-06 12:00:00');
        $first = $registry->forServer($server);
        Carbon::setTestNow('2026-10-06 12:04:59');
        self::assertSame($first, $registry->forServer($server));
        Carbon::setTestNow('2026-10-06 12:05:00');
        $second = $registry->forServer($server);
        self::assertNotSame($first, $second, 'a long-running process reads the panel\'s settings again at least this often');

        $registry->flush();
        self::assertNotSame($second, $registry->forServer($server));
    }

    public function testAServerNotStoredYetGetsADriverOfItsOwn(): void
    {
        $registry = $this->registry();
        $probe = new Server(['driver' => '3x-ui', 'base_url' => 'https://de1.example.com:2053/AbCdEf', 'api_token' => 'tok']);

        self::assertNotSame($registry->forServer($probe), $registry->forServer($probe));
    }

    public function testAnUnknownDriverOffersNothingAndCannotBeBuilt(): void
    {
        $registry = $this->registry();

        self::assertTrue($registry->has('3x-ui'));
        self::assertFalse($registry->has('marzban'));
        self::assertFalse($registry->capabilities('marzban')->inbounds);
        self::assertFalse($registry->capabilities('marzban')->linkRotation);
        self::assertTrue($registry->capabilities('3x-ui')->linkRotation);

        $registry->unregister('3x-ui');
        self::assertFalse($registry->has('3x-ui'));
        self::assertNull($registry->find('3x-ui'));
        $this->expectException(UnknownDriverException::class);
        $registry->forServer(self::stored(['api_token' => 'tok']));
    }

    public function testAConnectorIsRegisteredOnce(): void
    {
        $registry = $this->registry();

        $this->expectException(\LogicException::class);
        $registry->register(new ThreeXuiDriver());
    }

    private function registry(): ProviderRegistry
    {
        $this->app(); // the encrypter the Server's casts need

        $registry = new ProviderRegistry(new PanelHttp((new FakePanel())->client(), new NullLogger()));
        $registry->register(new ThreeXuiDriver());

        return $registry;
    }

    /**
     * A server as the database hands it over: every column there, the ones the test does not set empty.
     *
     * @param array<string, mixed> $attributes
     */
    private static function stored(array $attributes): Server
    {
        $server = new Server($attributes + ['name' => 'DE-1', 'driver' => '3x-ui', 'base_url' => 'https://de1.example.com:2053/AbCdEf', 'api_token' => null, 'username' => null, 'password' => null, 'totp_secret' => null, 'meta' => null]);
        $server->forceFill(['id' => 5]);
        $server->exists = true;

        return $server;
    }
}
