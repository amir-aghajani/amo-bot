<?php

declare(strict_types=1);

namespace App\Modules\Providers\Contracts;

use App\Core\Drivers\Driver;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelHttp;

/**
 * A panel connector (3x-ui, PasarGuard, …) — a stateless definition, registered in bootstrap/container.php
 * (`panel.drivers`); its key is what `servers.driver` holds. Its description (describe()) is the connector's card in the
 * add-server picker — its words, the versions it needs in its notes, and its traits: the two letters the picker draws
 * (MARK), who makes the panel (VENDOR), its docs (DOCS) and what its panels can do (Capabilities::traits()) — and the
 * form of a server's connection, which ServerService puts between the server's own columns: the fields every connector
 * shares (Support\PanelConnection, Support\PanelCredentials) and its own. A field is kept under its key: a column of
 * the server's, or `meta.<name>` for what has none. The client of one server's panel is connect()'s, which
 * ProviderRegistry::forServer() keeps a while.
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
