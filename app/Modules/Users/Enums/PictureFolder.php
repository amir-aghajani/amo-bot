<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/**
 * Where the shop keeps a kind of picture it was sent (Users\Services\CustomerPictures) — each kind in a folder of its
 * own under storage/uploads, the container's `<value>.path`: the receipts customers upload from the websites
 * (`payments.receipt_path`), and the pictures of the support tickets' messages (`ticket_messages.attachment_path`). A
 * picture sent in the bot is never copied: Telegram keeps it, the shop its file id.
 */
enum PictureFolder: string
{
    case Receipts = 'receipts';

    case Tickets = 'tickets';
}
