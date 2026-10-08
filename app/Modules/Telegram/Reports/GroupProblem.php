<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;

/**
 * What keeps the report group from taking reports — or a group from becoming it — as the shop keeps it (its value) and
 * as the screen and the group read it (message()): learned from a check, from the bot's own membership updates, or from
 * a refused send.
 */
enum GroupProblem: string
{
    case ForumOff = 'forum_off';
    case NotAdmin = 'not_admin';
    case NoTopicsRight = 'no_topics_right';
    case Removed = 'removed';
    /** The bot's rights could not be read just now (a group offered with the link): it is asked again with the next try. */
    case CheckFailed = 'check_failed';

    public function message(): string
    {
        return match ($this) {
            self::ForumOff => 'تاپیک‌های این گروه روشن نیست. در تنظیمات گروه گزینه Topics (تاپیک‌ها) را روشن کنید.',
            self::NotAdmin => 'ربات در این گروه ادمین نیست. ربات را ادمین گروه کنید و دسترسی «مدیریت تاپیک‌ها» (Manage Topics) را به آن بدهید.',
            self::NoTopicsRight => 'ربات در این گروه اجازه «مدیریت تاپیک‌ها» (Manage Topics) را ندارد. این دسترسی را در تنظیمات ادمین‌های گروه به ربات بدهید.',
            self::Removed => 'ربات دیگر عضو گروه گزارش‌ها نیست یا گروه حذف شده است. ربات را دوباره با لینک اتصال به گروه اضافه کنید.',
            self::CheckFailed => 'دسترسی ربات در این گروه بررسی نشد؛ چند لحظه بعد دوباره امتحان کنید.',
        };
    }

    /**
     * What a refused call says about the group itself — the bot taken out, demoted, without the "manage topics" right,
     * topics switched off; null when it is not the group's trouble (a flood limit, an outage, one bad report, a topic
     * to make or open again).
     */
    public static function of(TelegramApiException $e): ?self
    {
        return match ($e->refusal()) {
            Refusal::NoForum => self::ForumOff,
            Refusal::NoRights => stripos($e->getMessage(), 'topic') !== false ? self::NoTopicsRight : self::NotAdmin,
            Refusal::Forbidden, Refusal::ChatGone => self::Removed,
            default => null,
        };
    }

    /**
     * What the bot's membership says about reporting there: null for an admin with the "manage topics" right.
     *
     * @param array<string, mixed> $member A ChatMember of the bot
     */
    public static function ofMember(array $member): ?self
    {
        return match ((string) ($member['status'] ?? '')) {
            'administrator' => ($member['can_manage_topics'] ?? false) === true ? null : self::NoTopicsRight,
            'left', 'kicked' => self::Removed,
            default => self::NotAdmin,
        };
    }
}
