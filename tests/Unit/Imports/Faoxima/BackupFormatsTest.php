<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\Faoxima;

use App\Modules\Imports\Budget;
use App\Modules\Imports\Migrators\Faoxima\FaoximaMigrator;
use Tests\Unit\Imports\Sources\StagingTestCase;

/**
 * One Faoxima shop's data in every form its backup arrives in reads alike, through the real reading and the migrator:
 * Faoxima's own PHP writer (one INSERT a row, every value quoted), mysqldump's (extended INSERTs without column lists),
 * and a real MariaDB 10.11 mysqldump of that data — which opens with MariaDB's sandbox-mode comment (`/*M!999999\-
 * enable the sandbox mode`), as a backup Faoxima's Docker image makes does. The rows come in the order each file holds them (a real dump
 * writes a table in its primary key's order), so each kind is compared as a set.
 */
final class BackupFormatsTest extends StagingTestCase
{
    public function testEveryFormOfTheBackupReadsAsTheSameShop(): void
    {
        $read = [];
        foreach (['backup-php.sql', 'backup-mysqldump.sql', 'backup-mariadb.sql'] as $file) {
            $read[$file] = $this->read((string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/imports/faoxima/' . $file));
        }

        self::assertSame(8, count($read['backup-php.sql']['customers']), 'the fixture is read whole');
        foreach (['backup-mysqldump.sql', 'backup-mariadb.sql'] as $file) {
            self::assertSame($read['backup-php.sql'], $read[$file], $file);
        }
    }

    /**
     * What the migrator makes of a backup: its survey and every kind of record, each kind's records sorted.
     *
     * @return array<string, mixed>
     */
    private function read(string $backup): array
    {
        $migrator = new FaoximaMigrator();
        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload($backup, 'backup.sql'));
        self::assertTrue($this->stager()->stage($import, $migrator, Budget::unlimited()));
        self::assertTrue($migrator->recognize($this->stager()->schema($import))->ok);
        $staged = $this->stager()->staged($import, $migrator);

        $records = [
            'customers' => $migrator->customers($staged),
            'plans' => $migrator->plans($staged),
            'services' => $migrator->services($staged),
            'discount_codes' => $migrator->discountCodes($staged),
            'gift_codes' => $migrator->giftCodes($staged),
            'channels' => $migrator->channels($staged),
            'cards' => $migrator->cards($staged),
        ];
        $read = ['survey' => $migrator->survey($staged)->toArray()];
        foreach ($records as $kind => $yielded) {
            $read[$kind] = array_map(serialize(...), array_values(iterator_to_array($yielded)));
            sort($read[$kind]);
        }

        return $read;
    }
}
