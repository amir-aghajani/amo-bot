<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Core\Drivers\Driver;
use App\Core\Drivers\Registry;
use App\Core\Scheduling\Scheduler;
use App\Modules\Imports\Imports;
use App\Modules\Imports\Tasks\PruneImportsTask;
use Tests\DatabaseTestCase;

/**
 * How the shop is wired for imports, as bootstrap/container.php and bootstrap/schedule.php have it: the bots an import
 * reads are the registered migrators, and the imports are pruned once a day for the installation as a whole.
 */
final class WiringTest extends DatabaseTestCase
{
    public function testTheShopReadsTheRegisteredBots(): void
    {
        $migrators = $this->app()->container()->get('import.migrators');
        self::assertInstanceOf(Registry::class, $migrators);
        self::assertSame(['faoxima'], array_map(static fn(Driver $migrator): string => $migrator->key(), $migrators->all()));
        self::assertSame(['faoxima'], array_map(static fn(Driver $migrator): string => $migrator->key(), $this->service(Imports::class)->migrators()));
    }

    public function testTheImportsArePrunedDaily(): void
    {
        $task = $this->service(Scheduler::class)->tasks()[PruneImportsTask::class] ?? null;

        self::assertSame(['key' => 'prune_imports', 'interval' => 24 * 60 * 60, 'eachBot' => false], $task === null ? null : array_diff_key($task, ['task' => true]), 'once a day, for the installation as a whole');
    }
}
