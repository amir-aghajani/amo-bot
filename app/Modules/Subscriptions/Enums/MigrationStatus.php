<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/**
 * Where a move of a server's services stands (Models\ServerMigration): queued until its first turn, running, paused by
 * the owner — the three open ones, of which a server may be the source of one at a time —, then done, cancelled by the
 * owner, or failed: the target could take no more (full, switched off, no links), the reason kept.
 */
enum MigrationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Paused = 'paused';
    case Done = 'done';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /** @return list<self> The statuses of a job not over yet: the one a source server may have at a time. */
    public static function open(): array
    {
        return [self::Queued, self::Running, self::Paused];
    }

    /** @return list<self> The statuses a turn works a job in. */
    public static function workable(): array
    {
        return [self::Queued, self::Running];
    }

    /**
     * @param list<self> $statuses
     * @return list<string> Their stored values, for a query
     */
    public static function values(array $statuses): array
    {
        return array_map(static fn(self $status): string => $status->value, $statuses);
    }
}
