<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Notifications\Services\Notices;

/** Hourly, across every shop: deletes ended website sessions, expired sign-in challenges and notices past Notices::KEEP_DAYS. */
final class PruneAccountsTask implements Task
{
    public function __construct(
        private readonly CustomerSessions $sessions,
        private readonly AuthChallenges $challenges,
        private readonly Notices $notices,
    ) {}

    public function run(): void
    {
        $this->sessions->prune();
        $this->challenges->prune();
        $this->notices->prune();
    }
}
