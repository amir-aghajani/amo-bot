<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Notifications\Services\Notices;
use App\Modules\Settings\Services\ScheduleSettings;

/**
 * Hourly by default (the owner's «زمان‌بندی کارها»), across every shop: deletes ended website sessions, expired sign-in
 * challenges and notices older than the owner's days (ScheduleSettings::noticeDays()).
 */
final class PruneAccountsTask implements Task
{
    public function __construct(
        private readonly CustomerSessions $sessions,
        private readonly AuthChallenges $challenges,
        private readonly Notices $notices,
        private readonly ScheduleSettings $schedule,
    ) {}

    public function run(): void
    {
        $this->sessions->prune();
        $this->challenges->prune();
        $this->notices->prune($this->schedule->noticeDays());
    }
}
