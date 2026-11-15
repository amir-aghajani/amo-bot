<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Core\Drivers\Registry;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Exceptions\ImportRefusedException;
use App\Modules\Imports\Imports;
use App\Modules\Imports\Migrators\MirzaBot\MirzaBotMigrator;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Models\ImportRow;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\DatabaseTestCase;

/**
 * Whose data a backup is, when the bot the owner picked does not take it (Imports::read()): the bots the shop reads
 * asked in turn, the one that takes it named — MirzaBot's backup read as Faoxima's, Faoxima's (its descendant's) read
 * as MirzaBot's —; a database none of them takes refused as before, by the tables the picked one missed; and one with
 * the picked bot's tables and a kin's marks, when no bot the shop reads takes it, refused as not its own.
 */
final class WhoseDataTest extends DatabaseTestCase
{
    private const FIXTURES = __DIR__ . '/../../../Fixtures/imports';

    public function testAMirzaBotBackupReadAsFaoximasNamesMirzaBot(): void
    {
        foreach (['mirzabot/backup-php.sql', 'mirzabot/backup-mysqldump.sql'] as $fixture) {
            $import = $this->read('faoxima', self::fixture($fixture));

            self::assertSame(ImportPhase::Failed, $import->phase, $fixture);
            self::assertSame(sprintf(ImportRefusedException::ANOTHER_BOT, "\u{2068}MirzaBot\u{2069}", "\u{2068}Faoxima\u{2069}"), $import->error, "{$fixture}: Faoxima's own tables missing, MirzaBot takes it");
            self::assertSame(0, ImportRow::query()->where('import_id', $import->id)->count(), 'discarded as any refused import');
        }
    }

    public function testAFaoximaBackupReadAsMirzaBotsNamesFaoxima(): void
    {
        $import = $this->read('mirzabot', self::fixture('faoxima/backup-php.sql'));

        self::assertSame(ImportPhase::Failed, $import->phase);
        self::assertSame(sprintf(ImportRefusedException::ANOTHER_BOT, "\u{2068}Faoxima\u{2069}", "\u{2068}MirzaBot\u{2069}"), $import->error, "MirzaBot's tables, with Faoxima's marks: Faoxima's");
    }

    public function testADatabaseNeitherBotTakesIsRefusedByTheTablesThePickedOneMissed(): void
    {
        $other = "CREATE TABLE `users` (`id` int, `name` varchar(50));\nINSERT INTO `users` VALUES (1, 'Ali');\n";
        // MirzaBot's customers alone, without its services or panels: no bot's.
        $half = "CREATE TABLE `user` (`id` varchar(20), `Balance` int, `User_Status` varchar(20), `agent` varchar(10));\nINSERT INTO `user` VALUES ('1001', 0, 'Active', 'f');\n";

        self::assertSame(sprintf(ImportRefusedException::NOT_THIS_BOT, "\u{2068}Faoxima\u{2069}", "\u{2068}user\u{2069}، \u{2068}invoice\u{2069}، \u{2068}marzban_panel\u{2069}، …"), $this->read('faoxima', $other)->error);
        self::assertSame(sprintf(ImportRefusedException::NOT_THIS_BOT, "\u{2068}MirzaBot\u{2069}", "\u{2068}user\u{2069}، \u{2068}invoice\u{2069}، \u{2068}marzban_panel\u{2069}"), $this->read('mirzabot', $other)->error);
        self::assertSame(sprintf(ImportRefusedException::NOT_THIS_BOT, "\u{2068}MirzaBot\u{2069}", "\u{2068}invoice\u{2069}، \u{2068}marzban_panel\u{2069}"), $this->read('mirzabot', $half)->error);
        self::assertSame(sprintf(ImportRefusedException::NOT_THIS_BOT, "\u{2068}Faoxima\u{2069}", "\u{2068}invoice\u{2069}، \u{2068}marzban_panel\u{2069}، \u{2068}wallet_ledger\u{2069}"), $this->read('faoxima', $half)->error);
    }

    public function testADescendantsDataNoBotTheShopReadsTakesIsNotThePickedOnes(): void
    {
        // A shop that reads MirzaBot alone: Faoxima's backup has MirzaBot's tables and Faoxima's marks.
        $this->swap('import.migrators', new Registry([new MirzaBotMigrator()]), Imports::class);

        $import = $this->read('mirzabot', self::fixture('faoxima/backup-php.sql'));

        self::assertSame([ImportPhase::Failed, sprintf(ImportRefusedException::NOT_ITS, "\u{2068}MirzaBot\u{2069}")], [$import->phase, $import->error]);
    }

    /** A backup of `$bytes` uploaded for the bot `$migrator` and read in one step, as the import then stands. */
    private function read(string $migrator, string $bytes): Import
    {
        $imports = $this->service(Imports::class);
        $import = $imports->fromBackup($migrator, new UploadedFile((new StreamFactory())->createStream($bytes), 'backup.sql', null, strlen($bytes)), 'root');
        $imports->advance($import, Budget::unlimited());

        return $import;
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }
}
