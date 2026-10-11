<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Subscriptions\Services\ServiceReminders;
use Psr\Log\LoggerInterface;

/**
 * «یادآوری» on the clock (every 15 minutes, each shop, registered right after the sync so it reads fresh numbers):
 * sends the reminders that came due (ServiceReminders).
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
