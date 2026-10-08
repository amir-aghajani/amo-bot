<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Notifications\Services\Notices;

/**
 * Every hour, across every shop: the websites' sessions that ended and the sign-in challenges that expired go — none of
 * them opens anything any more —, and so do the notices kept for the customers' feeds once Notices::KEEP_DAYS old.
 */
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
