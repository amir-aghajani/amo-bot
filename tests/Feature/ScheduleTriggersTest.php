<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ScheduleRunCommand;
use App\Core\Http\Controllers\CronController;
use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Scheduler;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Fakes\CountingTask;
use Tests\Fakes\ListedShops;
use Tests\HttpTestCase;

/**
 * The two ways a host ticks the scheduler: `schedule:run` from a real cron, and GET /cron/{token} from an external
 * pinger. The clock is held half a minute into a minute: two ticks in a test are one minute's, whenever it runs.
 */
final class ScheduleTriggersTest extends HttpTestCase
{
    private CountingTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-26 12:00:30');
        $this->task = new CountingTask();
        $this->swap(CountingTask::class, $this->task);
        $scheduler = (new Scheduler($this->app()->container(), new ListedShops(), new Budget(), $this->service(LoggerInterface::class), $this->scratchDir() . '/schedule.json'))->everyMinutes(1, CountingTask::class);
        $this->swap(Scheduler::class, $scheduler, ScheduleRunCommand::class, CronController::class);
    }

    public function testTheCommandRunsWhatIsDueAndForcesTheRest(): void
    {
        $tester = new CommandTester($this->service(ScheduleRunCommand::class));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString(CountingTask::class, $tester->getDisplay());
        self::assertSame(1, $this->task->runs);

        self::assertSame(0, $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
        self::assertStringContainsString('No tasks due.', $tester->getDisplay(), 'the same minute: its moment ran already');
        self::assertSame(1, $this->task->runs);

        self::assertSame(0, $tester->execute(['--force' => true]));
        self::assertSame(2, $this->task->runs);
    }

    public function testTheWebTriggerNeedsTheConfiguredToken(): void
    {
        $off = $this->get('/cron/anything');
        self::assertSame(403, $off->getStatusCode(), 'no token configured: the trigger is off');
        self::assertSame(['message' => CronController::REFUSED, 'request_id' => $off->getHeaderLine('X-Request-Id')], $this->decode($off), 'the one error shape');

        $this->config(['shop.cron_token' => 'secret-token']);
        self::assertSame(403, $this->get('/cron/wrong')->getStatusCode());
        self::assertSame(0, $this->task->runs);

        $response = $this->get('/cron/secret-token');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ran' => [CountingTask::class]], $this->decode($response));
        self::assertSame(1, $this->task->runs);

        self::assertSame(['ran' => []], $this->decode($this->get('/cron/secret-token')), 'the same minute: its moment ran already');

        Carbon::setTestNow('2026-11-26 12:01:00');
        self::assertSame(['ran' => [CountingTask::class]], $this->decode($this->get('/cron/secret-token')), 'the next minute\'s moment: due');
    }
}
