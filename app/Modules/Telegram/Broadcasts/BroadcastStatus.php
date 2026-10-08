<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Broadcasts;

/** Where a broadcast's run stands: sending ↔ paused, then done — or cancelled. */
enum BroadcastStatus: string
{
    case Sending = 'sending';
    case Paused = 'paused';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /** Runs not finished: going, or waiting to go on. */
    public const OPEN = [self::Sending, self::Paused];

    public function isOpen(): bool
    {
        return in_array($this, self::OPEN, true);
    }

    /** In the admin's progress message. */
    public function label(): string
    {
        return match ($this) {
            self::Sending => 'در حال ارسال',
            self::Paused => 'متوقف شده',
            self::Done => 'تمام شد',
            self::Cancelled => 'لغو شد',
        };
    }
}
