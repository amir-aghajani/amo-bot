<?php

declare(strict_types=1);

namespace App\Modules\Support\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Settings\Services\ScheduleSettings;
use App\Modules\Support\Services\TicketAttachments;
use App\Modules\Telegram\Reports\ReportSender;

/**
 * The tickets' housekeeping, hourly by default in every shop: the pictures uploaded to tickets closed the owner's days
 * ago (ScheduleSettings::pictureDays()) are deleted, and the report group's reports of closed (or deleted) tickets are
 * forgotten once as old as the owner keeps reports (reportDays(), a week by default). Reports of an open ticket stay,
 * since a reply to any of them answers it.
 */
final class PruneTicketsTask implements Task
{
    public function __construct(
        private readonly TicketAttachments $attachments,
        private readonly ReportSender $reports,
        private readonly ScheduleSettings $schedule,
    ) {}

    public function run(): void
    {
        $this->attachments->prune($this->schedule->pictureDays());
        $this->reports->pruneTickets($this->schedule->reportDays());
    }
}
