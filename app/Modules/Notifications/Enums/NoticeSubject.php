<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * What a notice is about, for the website's link to it (`notifications.subject_type`): one of the customer's orders —
 * a notice about a payment is about its order, the one its words name and the website opens (`GET /orders/{id}`, its
 * payments in it) —, one of their services, or one of their support tickets. A notice about their account, or about
 * someone else's row (a referral's payment, an agent's customer's order), is about nothing the website opens.
 */
enum NoticeSubject: string
{
    case Order = 'order';

    case Subscription = 'subscription';

    case Ticket = 'ticket';
}
