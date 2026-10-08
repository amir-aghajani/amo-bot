<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Core\Scheduling\Shops;

/** Shops for the scheduler's own tests: the ids a test lists, and where work ran, in order (null: everywhere). */
final class ListedShops implements Shops
{
    /** @var list<int|null> */
    public array $ran = [];

    /** Plays a database that is down: the shops cannot be read. */
    public bool $unreadable = false;

    /** @var list<int> */
    public array $ids = [1];

    public function ids(): array
    {
        return $this->unreadable ? throw new \RuntimeException('the database is down') : $this->ids;
    }

    public function in(int $shop, \Closure $work): void
    {
        $this->ran[] = $shop;
        $work();
    }

    public function everywhere(\Closure $work): void
    {
        $this->ran[] = null;
        $work();
    }
}
