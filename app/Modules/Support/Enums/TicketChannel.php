<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

use App\Modules\Auth\Actor;
use App\Modules\Auth\ActorKind;

/**
 * Where a ticket's message was written (`ticket_messages.channel`): the website or the bot (the customer's), or a panel,
 * the website's admin API or the report group (support's).
 */
enum TicketChannel: string
{
    case Web = 'web';

    case Bot = 'bot';

    case Panel = 'panel';

    case Staff = 'staff';

    case Group = 'group';

    /**
     * Where support wrote, by who wrote it: a panel's principal on the panel, a shop admin on the website or in the
     * report group.
     *
     * @throws \LogicException for the shop itself, which writes no ticket's message, and a customer, who writes as one
     */
    public static function of(Actor $actor): self
    {
        return match ($actor->kind) {
            ActorKind::Owner, ActorKind::Agent => self::Panel,
            ActorKind::Staff => self::Staff,
            ActorKind::GroupAdmin => self::Group,
            ActorKind::System => throw new \LogicException('The shop itself writes no ticket\'s message.'),
            ActorKind::Customer => throw new \LogicException('A customer writes in their own ticket from the bot or the website, never as support.'),
        };
    }

    /** Where it came from, as the report group reads it («از وب‌سایت»). */
    public function source(): string
    {
        return match ($this) {
            self::Web => 'از وب‌سایت',
            self::Bot => 'از ربات',
            self::Panel => 'از پنل',
            self::Staff => 'از مدیریت وب‌سایت',
            self::Group => 'از گروه گزارش‌ها',
        };
    }
}
