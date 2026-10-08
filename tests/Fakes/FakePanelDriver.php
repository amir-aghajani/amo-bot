<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Form;
use App\Modules\Providers\Contracts\PanelDriver;
use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelHttp;

/**
 * The fake panels' connector, driver "fake" (a Server with `driver => FakeProvider::driver()`): TestCase::fakePanel()
 * registers it for a test and forgets it after. Its panels are FakeProviders, which can do what FakeProvider::$capabilities
 * says; a server on it has nothing of a connection to fill in.
 */
final class FakePanelDriver implements PanelDriver
{
    public const KEY = 'fake';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): Descriptor
    {
        return new Descriptor(self::KEY, 'Fake', 'A panel for tests', new Form(self::KEY, []));
    }

    public function capabilities(): Capabilities
    {
        return FakeProvider::$capabilities;
    }

    public function connect(Server $server, PanelHttp $http): ProviderInterface
    {
        return new FakeProvider($server);
    }
}
