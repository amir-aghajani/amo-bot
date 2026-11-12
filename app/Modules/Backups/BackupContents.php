<?php

declare(strict_types=1);

namespace App\Modules\Backups;

/** What a backup file holds, read and checked whole (BackupReader::read()): what it is of, by which version, when, and its tables. */
final readonly class BackupContents
{
    /**
     * @param array{id: int, username: string|null, title: string|null}|null $shop The shop it is of; null for the whole installation
     * @param array<string, int> $rows Each table's rows
     */
    public function __construct(
        public BackupScope $scope,
        public ?array $shop,
        public string $appVersion,
        public string $madeAt,
        public array $rows,
    ) {}

    public function total(): int
    {
        return array_sum($this->rows);
    }
}
