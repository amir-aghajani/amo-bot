<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Scheduler;
use App\Core\Scheduling\Shops;
use App\Modules\Bots\Models\Bot;
use Psr\Log\LoggerInterface;
use Tests\DatabaseTestCase;
use Tests\Fakes\ShopRecordingTask;

/**
 * The shops the scheduler goes round are the bots': a shop's own work runs in the main bot's shop and then each agent's
 * bot that runs (not one without a token), the servers' work once across all of them.
 */
final class SchedulerShopsTest extends DatabaseTestCase
{
    public function testEachShopsOwnWorkRunsInEachShopAndTheServersWorkAcrossThem(): void
    {
        $serving = $this->agentBot();
        $this->agentBot($this->agent(overrides: ['telegram_id' => 900_003]), overrides: ['token' => null, 'telegram_id' => null]);

        self::assertSame([Bot::MAIN, $serving->id], $this->service(Shops::class)->ids(), "the main bot's shop, then each agent's bot that runs");

        ShopRecordingTask::$shops = [];
        $this->scheduler()->everyMinutes(1, ShopRecordingTask::class, eachBot: true)->run();
        self::assertSame([Bot::MAIN, $serving->id], ShopRecordingTask::$shops);

        ShopRecordingTask::$shops = [];
        $this->scheduler()->everyMinutes(1, ShopRecordingTask::class)->run();
        self::assertSame([null], ShopRecordingTask::$shops, 'a task of the servers: once, across every shop');
    }

    private function scheduler(): Scheduler
    {
        return new Scheduler($this->app()->container(), $this->service(Shops::class), new Budget(), $this->service(LoggerInterface::class), $this->scratchDir() . '/schedule.json');
    }
}
