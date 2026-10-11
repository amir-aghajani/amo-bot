<?php

declare(strict_types=1);

namespace App\Modules\Support\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Support\Services\TicketAttachments;
use App\Modules\Telegram\Reports\ReportSender;

/**
 * The tickets' housekeeping, hourly in every shop: the pictures uploaded to tickets closed TicketAttachments::KEEP_DAYS
 * ago are deleted, and the report group's reports of closed (or deleted) tickets are forgotten once a week old. Reports
 * of an open ticket stay, since a reply to any of them answers it.
 */
final class PruneTicketsTask implements Task
{
    public function __construct(
        private readonly TicketAttachments $attachments,
        private readonly ReportSender $reports,
    ) {}

    public function run(): void
    {
        $this->attachments->prune();
        $this->reports->pruneTickets();
    }
}
