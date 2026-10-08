<?php

declare(strict_types=1);

namespace App\Modules\Support\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Support\Services\TicketAttachments;
use App\Modules\Telegram\Reports\ReportSender;

/**
 * Every hour, in every shop: the tickets' housekeeping — the pictures uploaded to tickets closed TicketAttachments::
 * KEEP_DAYS ago go from the host (their messages say they had one), and the report group's reports of tickets closed —
 * or gone — are forgotten once a week old, as every other report is (those of a ticket still open stay: a reply to any of
 * them answers it).
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
