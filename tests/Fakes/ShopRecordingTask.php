<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Core\Scheduling\Task;
use App\Modules\Bots\CurrentBot;

/** A scheduled task that only notes whose shop it ran in, each time (null: across every shop). */
final class ShopRecordingTask implements Task
{
    /** @var list<int|null> */
    public static array $shops = [];

    public function run(): void
    {
        self::$shops[] = CurrentBot::scope();
    }
}
