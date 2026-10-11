<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * What a notice is about, for the website's link to it (`notifications.subject_type`): one of the customer's orders (a
 * payment's notice is about its order), services or tickets. A notice about their account, or about someone else's row
 * (a referral's payment, an agent's customer's order), links to nothing.
 */
enum NoticeSubject: string
{
    case Order = 'order';

    case Subscription = 'subscription';

    case Ticket = 'ticket';
}
