<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/**
 * The folder (the container's `<value>.path`, under storage/uploads) for each kind of uploaded picture: website
 * receipts and support tickets' pictures. Pictures sent in the bot stay on Telegram.
 */
enum PictureFolder: string
{
    case Receipts = 'receipts';

    case Tickets = 'tickets';
}
