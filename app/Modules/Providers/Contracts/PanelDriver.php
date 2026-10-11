<?php

declare(strict_types=1);

namespace App\Modules\Providers\Contracts;

use App\Core\Drivers\Driver;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelHttp;

/**
 * A panel connector (3x-ui, PasarGuard, …): a stateless definition registered in bootstrap/container.php
 * (`panel.drivers`); its key is what `servers.driver` holds. describe() is its card in the add-server picker — words,
 * notes (the versions it needs), traits (MARK, VENDOR, DOCS, Capabilities::traits()) — and the form of a server's
 * connection: the shared fields (Support\PanelConnection, Support\PanelCredentials) plus its own, each kept under its
 * key (a column of `servers`, or `meta.<name>`). connect() builds the client of one server's panel, which
 * ProviderRegistry::forServer() reuses a while. A new connector needs no edit outside its own folder but its
 * registration and its request shapes in the API description.
 */
interface PanelDriver extends Driver
{
    /** The trait (Descriptor::$traits) the picker's card draws the connector by: two letters ("3X"). */
    public const MARK = 'mark';

    /** The trait that says who makes the panel. */
    public const VENDOR = 'vendor';

    /** The trait that links to the panel's own pages, when it has any. */
    public const DOCS = 'docs_url';

    /** What its panels can do beyond the contract's minimum. */
    public function capabilities(): Capabilities;

    /** The client of one server's panel, talking through `$http`. */
    public function connect(Server $server, PanelHttp $http): ProviderInterface;
}
