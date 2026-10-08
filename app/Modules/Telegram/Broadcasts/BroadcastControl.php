<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Broadcasts;

/** What the admin does to a run under way — from its progress message in the bot, or from the panel. */
enum BroadcastControl: string
{
    case Pause = 'pause';
    case Resume = 'resume';
    case Cancel = 'cancel';

    /** @return list<BroadcastStatus> The states it moves a run from */
    public function movesFrom(): array
    {
        return match ($this) {
            self::Pause => [BroadcastStatus::Sending],
            self::Resume => [BroadcastStatus::Paused],
            self::Cancel => BroadcastStatus::OPEN,
        };
    }

    public function movesTo(): BroadcastStatus
    {
        return match ($this) {
            self::Pause => BroadcastStatus::Paused,
            self::Resume => BroadcastStatus::Sending,
            self::Cancel => BroadcastStatus::Cancelled,
        };
    }
}
