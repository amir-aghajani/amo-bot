<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Subscriptions\Services\ServiceReminders;
use Psr\Log\LoggerInterface;

/**
 * «یادآوری» on the clock: the services that got near their end or their traffic's since the last run are
 * told so (ServiceReminders), right after the sync has read their panels.
 */
final class SendRemindersTask implements Task
{
    public function __construct(
        private readonly ServiceReminders $reminders,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        $sent = $this->reminders->send();
        if ($sent > 0) {
            $this->logger->info('Sent {count} service reminders', ['count' => $sent]);
        }
    }
}
