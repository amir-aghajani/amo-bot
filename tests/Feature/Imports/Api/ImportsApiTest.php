<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Api;

use App\Core\Drivers\Registry;
use App\Core\Http\Middleware\JsonBodyMiddleware;
use App\Core\Support\FileLock;
use App\Modules\Admin\Api\ImportsController;
use App\Modules\Bots\Models\Bot;
use App\Modules\Imports\Exceptions\ImportRefusedException;
use App\Modules\Imports\Imports;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedPanel;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Imports\Sources\Stager;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Support\Validation;
use Tests\Fakes\FakeMigrator;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;
use Tests\Unit\Imports\Sources\SqliteDialect;

/**
 * «انتقال از ربات دیگر» through the owner's panel: a backup file uploaded, read a step a request to its review, the
 * choices saved, started and written a step a request to its end, its report paged by reason; each refusal under its
 * field; an import under way cancelled, an ended one's record deleted. The owner's alone, always into the main shop.
 */
final class ImportsApiTest extends HttpTestCase
{
    /** The fake bot's backup, as phpMyAdmin exports it: the one table it reads (ImportRunCommandTest's too). */
    public const DUMP = <<<'SQL'
        -- phpMyAdmin SQL Dump
        SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
        CREATE TABLE `members` (
          `id` int(11) NOT NULL,
          `name` varchar(64) DEFAULT NULL,
          `secret` varchar(64) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        INSERT INTO `members` (`id`, `name`, `secret`) VALUES
        (1, 'Ali', 'pw-1'),
        (2, 'Sara', NULL);
        COMMIT;

        SQL;

    /** How many continue calls a loop makes at most before the test gives up on it. */
    private const MOST_STEPS = 20;

    private FakeMigrator $migrator;

    private string $folder;

    /** The other bot's database: a SQLite file standing in for its MySQL one. */
    private SqliteDialect $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrator = new FakeMigrator();
        $this->folder = $this->scratchDir();
        $this->database = new SqliteDialect();
        $container = $this->app()->container();
        $stager = $container->make(Stager::class, ['folder' => $this->folder, 'dialect' => $this->database]);
        $this->swap(Stager::class, $stager);
        $this->swap(Imports::class, $container->make(Imports::class, ['migrators' => new Registry([$this->migrator]), 'stager' => $stager, 'folder' => $this->folder]), ImportsController::class);
        $this->loginAsAdmin();
    }

    public function testThePageOffersTheBotsAnImportReadsAndNothingUnderWay(): void
    {
        $page = $this->decode($this->get('/api/admin/imports'));

        self::assertSame([null, []], [$page['current'], $page['history']]);
        self::assertSame([[FakeMigrator::KEY, FakeMigrator::LABEL, ['mark' => 'FK', 'vendor' => 'https://example.com/fake-bot']]], array_map(static fn(array $migrator): array => [$migrator['key'], $migrator['label'], $migrator['traits']], $page['migrators']));
    }

    public function testABackupIsReadAStepARequestToItsReviewWithTheProposedChoices(): void
    {
        $berlin = $this->sellingServer('Berlin', ['base_url' => 'https://berlin.test']);
        $this->migrator->panels = [self::panel('de', 'https://berlin.test:443/panel'), self::panel('nl', 'https://amsterdam.test', addable: false)];
        $this->migrator->customers = [new ImportedCustomer('1', 501, 'ali', balance: '25000')];

        $uploaded = $this->upload('/api/admin/imports', 'file', 'backup.sql', self::DUMP, ['migrator' => FakeMigrator::KEY]);
        self::assertSame(201, $uploaded->getStatusCode(), (string) $uploaded->getBody());
        $import = $this->decode($uploaded)['import'];
        self::assertSame([FakeMigrator::KEY, FakeMigrator::LABEL, 'backup', 'backup.sql', 'reading', self::ADMIN_USERNAME], [$import['migrator'], $import['migrator_label'], $import['source'], $import['file_name'], $import['phase'], $import['reviewer']]);
        self::assertSame([null, strlen(self::DUMP)], [$import['progress']['stage'], $import['progress']['total']], 'the bytes of the file to read');
        self::assertSame($import['id'], $this->decode($this->get('/api/admin/imports'))['current']['id'] ?? null, 'the import under way, which the page comes back to');

        $read = $this->continueWhile($import['id'], 'reading');
        self::assertSame(['review', '1.0', null], [$read['phase'], $read['version'], $read['choices']]);
        self::assertSame(1, $read['survey']['counts']['customers']);
        self::assertSame([['de', $berlin->id, true], ['nl', null, false]], array_map(static fn(array $panel): array => [$panel['key'], $panel['suggested_server_id'], $panel['addable']], $read['survey']['panels']), 'a server of the same connector on the same address suggested');
        self::assertSame(['de' => ['action' => 'server', 'server_id' => $berlin->id], 'nl' => ['action' => 'skip', 'server_id' => null]], $read['proposed']['panels']);
        self::assertSame([true, false], [$read['proposed']['balances'], $read['proposed']['admins']]);
        self::assertSame(2, ImportRow::query()->where('import_id', $import['id'])->count(), 'the members staged');
        self::assertSame($read, $this->decode($this->get("/api/admin/imports/{$import['id']}"))['import']);
    }

    public function testTheChoicesAreSavedAndTheImportIsWrittenAStepARequestToItsReport(): void
    {
        $berlin = $this->sellingServer('Berlin', ['base_url' => 'https://berlin.test']);
        FakeProvider::put($berlin, new ClientInfo(name: 'ali_1', enabled: true, subscriptionUrl: 'https://berlin.test/sub/ali_1'));
        $this->migrator->panels = [self::panel('de', 'https://berlin.test', services: 2)];
        $this->migrator->customers = [new ImportedCustomer('1', 501, 'ali', balance: '25000'), new ImportedCustomer('2', 0, 'nobody'), new ImportedCustomer('3', 503, 'sara')];
        $this->migrator->services = [new ImportedService('s1', '1', 'de', 'ali_1'), new ImportedService('s2', '3', 'de', 'gone_1')];
        $id = $this->reviewed();

        $saved = $this->decode($this->putJson("/api/admin/imports/{$id}/choices", ['choices' => ['panels' => ['de' => ['action' => 'server', 'server_id' => $berlin->id]], 'phones' => false]]))['import'];
        self::assertSame(['review', ['de' => ['action' => 'server', 'server_id' => $berlin->id]], false, true, null], [$saved['phase'], $saved['choices']['panels'], $saved['choices']['phones'], $saved['choices']['balances'], $saved['proposed']], 'a switch left out takes its default');

        $started = $this->decode($this->postJson("/api/admin/imports/{$id}/start"))['import'];
        self::assertSame(['importing', 'servers'], [$started['phase'], $started['progress']['stage']]);
        self::assertNotNull($started['started_at']);

        $done = $this->continueWhile($id, 'importing');
        self::assertSame('done', $done['phase'], (string) $done['error']);
        self::assertNotNull($done['finished_at']);
        self::assertSame(['created' => 2, 'linked' => 0, 'skipped' => 1], $done['counts']['customers']);
        self::assertSame('25000.00', User::query()->where('telegram_id', 501)->sole()->balance());
        self::assertSame(['ali_1'], Subscription::query()->pluck('remote_name')->all(), 'the service adopted as it stands on the panel');
        self::assertSame(['total' => 2, 'by_reason' => [
            ['reason' => 'not_telegram', 'label' => 'شناسه تلگرام معتبر ندارد', 'count' => 1],
            ['reason' => 'not_on_panel', 'label' => 'روی پنلش پیدا نشد', 'count' => 1],
        ]], $done['problems']);
        self::assertSame(0, ImportRow::query()->where('import_id', $id)->count(), 'the staged copy gone once it ended');

        $page = $this->decode($this->get('/api/admin/imports'));
        self::assertSame([null, [$id]], [$page['current'], array_column($page['history'], 'id')], 'ended: in the history');
        self::assertSame('done', $this->decode($this->postJson("/api/admin/imports/{$id}/continue"))['import']['phase'], 'a step on an ended import changes nothing');
    }

    public function testTheReportListsItsProblemsAPageAtATimeByReasonAndKind(): void
    {
        $this->migrator->customers = array_map(static fn(int $i): ImportedCustomer => new ImportedCustomer("c{$i}", 0, "nobody{$i}"), range(1, 55));
        $this->migrator->services = [new ImportedService('s1', 'c1', 'nl', 'gone_1')];
        $this->migrator->panels = [self::panel('nl', 'https://amsterdam.test', services: 1, addable: false)];
        $id = $this->reviewed();
        $this->putJson("/api/admin/imports/{$id}/choices", ['choices' => ['panels' => ['nl' => ['action' => 'skip', 'server_id' => null]]]]);
        $this->postJson("/api/admin/imports/{$id}/start");
        self::assertSame('done', $this->continueWhile($id, 'importing')['phase']);

        $first = $this->decode($this->get("/api/admin/imports/{$id}/problems"));
        self::assertSame([55, 1, 50, 50], [$first['total'], $first['page'], $first['per_page'], count($first['problems'])]);
        self::assertSame(['kind' => 'customers', 'source_key' => 'c1', 'reason' => 'not_telegram', 'label' => 'شناسه تلگرام معتبر ندارد'], array_intersect_key($first['problems'][0], array_flip(['kind', 'source_key', 'reason', 'label'])));
        self::assertSame(['c51', 'c52', 'c53', 'c54', 'c55'], array_column($this->decode($this->get("/api/admin/imports/{$id}/problems?page=2"))['problems'], 'source_key'));
        self::assertSame(55, $this->decode($this->get("/api/admin/imports/{$id}/problems?reason=not_telegram&kind=customers"))['total']);
        self::assertSame(0, $this->decode($this->get("/api/admin/imports/{$id}/problems?kind=plans"))['total']);
        self::assertSame(55, $this->decode($this->unchecked()->get("/api/admin/imports/{$id}/problems?reason=&kind="))['total'], 'empty filters, as left out');
        self::assertSame(0, $this->decode($this->get("/api/admin/imports/{$id}/problems?reason=panel_skipped"))['total'], 'a counted reason has no rows…');
        self::assertContains(['reason' => 'panel_skipped', 'label' => 'پنلش منتقل نشد', 'count' => 1], $this->decode($this->get("/api/admin/imports/{$id}"))['import']['problems']['by_reason'], '…its count is in the import');
    }

    public function testWhatCannotBeginIsRefusedUnderItsFieldAndNothingIsKept(): void
    {
        $this->assertRefusedUnder('migrator', ImportRefusedException::UNKNOWN_MIGRATOR, $this->upload('/api/admin/imports', 'file', 'backup.sql', self::DUMP, ['migrator' => 'mirza']));
        $this->assertRefusedUnder('file', ImportRefusedException::NO_FILE, $this->unchecked()->postJson('/api/admin/imports', ['migrator' => FakeMigrator::KEY]));
        $this->assertRefusedUnder('file', ImportRefusedException::NOT_A_DUMP, $this->upload('/api/admin/imports', 'file', 'backup.sql', "Hello, this is no database.\n", ['migrator' => FakeMigrator::KEY]));

        $missing = $this->postJson('/api/admin/imports/database', ['migrator' => FakeMigrator::KEY, 'host' => '', 'database' => '', 'username' => '']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame([[ImportRefusedException::HOST_MISSING], [ImportRefusedException::DATABASE_MISSING], [ImportRefusedException::USERNAME_MISSING]], array_values(array_intersect_key($this->decode($missing)['errors'], array_flip(['host', 'database', 'username']))), 'every refusal at once');
        self::assertSame(0, Import::query()->count(), 'nothing kept of a refused one');
        self::assertSame([], array_values(array_diff((array) scandir($this->folder), ['.', '..', 'begin.lock'])), 'no file left behind');

        $id = $this->reviewed();
        $this->assertRefusedUnder('choices', ImportsController::NO_CHOICES, $this->unchecked()->putJson("/api/admin/imports/{$id}/choices", ['choices' => 'all']));
        $this->assertRefusedUnder('choices.balances', Validation::NOT_A_SWITCH, $this->unchecked()->putJson("/api/admin/imports/{$id}/choices", ['choices' => ['balances' => 'maybe']]));
    }

    public function testTheOtherBotsDatabaseIsTriedAtOnceThenReadToItsReview(): void
    {
        $file = $this->scratchDir() . '/bot.sqlite';
        $old = new \PDO('sqlite:' . $file);
        $old->exec('CREATE TABLE members (id INTEGER PRIMARY KEY, name TEXT, secret TEXT)');
        $old->exec("INSERT INTO members VALUES (1, 'Ali', 'pw-1'), (2, 'Sara', NULL), (3, 'Reza', 'pw-3')");
        $this->migrator->customers = [new ImportedCustomer('1', 501, 'ali')];

        $begun = $this->postJson('/api/admin/imports/database', ['migrator' => FakeMigrator::KEY, 'host' => 'localhost', 'port' => '3306', 'database' => $file, 'username' => 'bot', 'password' => 'db-secret']);

        self::assertSame(201, $begun->getStatusCode(), (string) $begun->getBody());
        $import = $this->decode($begun)['import'];
        self::assertSame(['database', null, 'reading', 0], [$import['source'], $import['file_name'], $import['phase'], $import['progress']['done']]);
        self::assertSame([['host' => 'localhost', 'port' => 3306, 'database' => $file, 'username' => 'bot', 'password' => 'db-secret']], $this->database->connections, 'tried at once, as typed');
        self::assertStringNotContainsString('db-secret', (string) $begun->getBody(), 'the password never sent back');

        $read = $this->continueWhile($import['id'], 'reading');
        self::assertSame(['review', 1], [$read['phase'], $read['survey']['counts']['customers']]);
        self::assertSame(3, ImportRow::query()->where('import_id', $import['id'])->count(), 'the members staged');

        $this->database->refusal = new \PDOException("SQLSTATE[HY000] [1045] Access denied for user 'bot'@'localhost' (using password: YES)", 1045);
        $this->deleteJson("/api/admin/imports/{$import['id']}");
        $denied = $this->postJson('/api/admin/imports/database', ['migrator' => FakeMigrator::KEY, 'host' => 'localhost', 'database' => $file, 'username' => 'bot', 'password' => 'wrong']);
        self::assertSame(422, $denied->getStatusCode());
        self::assertSame(['username', 'password'], array_keys($this->decode($denied)['errors']), 'refused where the owner fixes it');
        self::assertStringNotContainsString('wrong', (string) $denied->getBody());
    }

    public function testDataOfAnotherBotFailsTheImportInTheOwnersWords(): void
    {
        $id = $this->decode($this->upload('/api/admin/imports', 'file', 'backup.sql', "CREATE TABLE `users` (`id` int);\nINSERT INTO `users` VALUES (1);\n", ['migrator' => FakeMigrator::KEY]))['import']['id'];

        $failed = $this->continueWhile($id, 'reading');

        self::assertSame('failed', $failed['phase']);
        self::assertSame(sprintf(ImportRefusedException::NOT_THIS_BOT, "\u{2068}" . FakeMigrator::LABEL . "\u{2069}", "\u{2068}members\u{2069}"), $failed['error']);
        self::assertNotNull($failed['finished_at']);
        self::assertSame([null, null], [$failed['survey'], $failed['proposed']]);
        self::assertSame([null, [$id]], [$this->decode($this->get('/api/admin/imports'))['current'], array_column($this->decode($this->get('/api/admin/imports'))['history'], 'id')], 'ended: another may begin');
        self::assertSame(0, ImportRow::query()->where('import_id', $id)->count(), 'what it read gone');
    }

    public function testAnUploadPastWhatPhpTakesIsTooLarge(): void
    {
        $limit = ini_parse_quantity((string) ini_get('post_max_size'));
        if ($limit <= 0) {
            self::markTestSkipped('This PHP takes a POST of any size: it empties none.');
        }

        // What reaches the app of a backup past post_max_size: its Content-Length, and nothing else — not even `migrator`.
        $emptied = $this->unchecked()->send('POST', '/api/admin/imports', null, ['X-Requested-With' => 'XMLHttpRequest', 'Content-Type' => 'multipart/form-data; boundary=amobot-test', 'Content-Length' => (string) ($limit + 1)]);

        self::assertSame(413, $emptied->getStatusCode(), (string) $emptied->getBody());
        self::assertSame(JsonBodyMiddleware::TOO_LARGE, $this->decode($emptied)['message']);
        self::assertSame(0, Import::query()->count());
    }

    public function testAStepAlreadyOnTheImportIsSaidSoAndNothingDone(): void
    {
        $id = $this->decode($this->upload('/api/admin/imports', 'file', 'backup.sql', self::DUMP, ['migrator' => FakeMigrator::KEY]))['import']['id'];
        $lock = FileLock::take("{$this->folder}/{$id}.lock") ?? self::fail("The import's lock could not be taken.");

        try {
            $busy = $this->decode($this->postJson("/api/admin/imports/{$id}/continue"));
        } finally {
            $lock->release();
        }

        self::assertSame([false, 'reading', 0], [$busy['stepped'], $busy['import']['phase'], $busy['import']['progress']['done']], 'another step on it: left to that one, the page told to wait');
        self::assertSame(0, ImportRow::query()->where('import_id', $id)->count(), 'nothing read');

        $free = $this->decode($this->postJson("/api/admin/imports/{$id}/continue"));
        self::assertSame([true, 'review'], [$free['stepped'], $free['import']['phase']], 'once it let go, the step runs');
    }

    public function testOneImportAtATimeAndEachStepOnlyInItsPhase(): void
    {
        $id = $this->decode($this->upload('/api/admin/imports', 'file', 'backup.sql', self::DUMP, ['migrator' => FakeMigrator::KEY]))['import']['id'];

        $this->assertRefusedUnder('import', ImportRefusedException::BUSY, $this->upload('/api/admin/imports', 'file', 'other.sql', self::DUMP, ['migrator' => FakeMigrator::KEY]));
        $this->assertRefusedUnder('import', ImportRefusedException::BUSY, $this->postJson('/api/admin/imports/database', ['migrator' => FakeMigrator::KEY, 'host' => 'db.example', 'database' => 'bot', 'username' => 'bot']));
        $this->assertRefusedUnder('import', ImportRefusedException::NOT_NOW, $this->putJson("/api/admin/imports/{$id}/choices", ['choices' => ['balances' => true]]), message: 'reading: no choices yet');
        $this->assertRefusedUnder('import', ImportRefusedException::NOT_NOW, $this->postJson("/api/admin/imports/{$id}/start"));
        self::assertSame(1, Import::query()->count());

        self::assertSame(404, $this->get('/api/admin/imports/' . ($id + 1))->getStatusCode());
        self::assertSame(404, $this->postJson('/api/admin/imports/' . ($id + 1) . '/continue')->getStatusCode());
    }

    public function testAnImportUnderWayIsCancelledAndAnEndedOnesRecordDeleted(): void
    {
        $id = $this->decode($this->upload('/api/admin/imports', 'file', 'backup.sql', self::DUMP, ['migrator' => FakeMigrator::KEY]))['import']['id'];
        $this->continueWhile($id, 'reading');
        self::assertSame(2, ImportRow::query()->where('import_id', $id)->count());

        self::assertSame(204, $this->deleteJson("/api/admin/imports/{$id}")->getStatusCode());
        $cancelled = $this->decode($this->get("/api/admin/imports/{$id}"))['import'];
        self::assertSame('cancelled', $cancelled['phase']);
        self::assertSame(0, ImportRow::query()->where('import_id', $id)->count(), 'its staged copy gone');
        self::assertFalse(is_dir("{$this->folder}/{$id}"), 'its file gone');
        self::assertSame([null, [$id]], [$this->decode($this->get('/api/admin/imports'))['current'], array_column($this->decode($this->get('/api/admin/imports'))['history'], 'id')]);
        self::assertSame(201, $this->upload('/api/admin/imports', 'file', 'backup.sql', self::DUMP, ['migrator' => FakeMigrator::KEY])->getStatusCode(), 'another may begin');

        self::assertSame(204, $this->deleteJson("/api/admin/imports/{$id}")->getStatusCode());
        self::assertSame(404, $this->get("/api/admin/imports/{$id}")->getStatusCode(), 'an ended one: its record deleted');
    }

    public function testItIsTheOwnersAloneAndAlwaysTheMainShops(): void
    {
        $bot = $this->agentBot();
        $this->openShop($bot);
        $this->migrator->customers = [new ImportedCustomer('1', 501, 'ali')];
        $id = $this->reviewed();
        $this->postJson("/api/admin/imports/{$id}/start");
        self::assertSame('done', $this->continueWhile($id, 'importing')['phase']);
        self::assertSame(Bot::MAIN, Import::query()->findOrFail($id)->bot_id);
        self::assertSame(Bot::MAIN, User::query()->withoutGlobalScopes()->where('telegram_id', 501)->sole()->bot_id, "brought into the main shop, whichever shop the owner's tab shows");
        self::assertSame([$id], array_column($this->decode($this->get('/api/admin/imports'))['history'], 'id'));

        $_SESSION = [];
        self::assertSame(401, $this->get('/api/admin/imports')->getStatusCode());
        $this->loginAsAgent($bot);
        self::assertSame(404, $this->get('/api/agent/imports')->getStatusCode(), "an agent's panel has none");
        self::assertSame(404, $this->get("/api/agent/imports/{$id}")->getStatusCode());
    }

    /** The fake bot's backup read through to its review, as the page does; its id. */
    private function reviewed(): int
    {
        $id = $this->decode($this->upload('/api/admin/imports', 'file', 'backup.sql', self::DUMP, ['migrator' => FakeMigrator::KEY]))['import']['id'];
        self::assertSame('review', $this->continueWhile($id, 'reading')['phase']);

        return $id;
    }

    /**
     * Continue the import while it is in `$phase`, as the page does: the import as it left it.
     *
     * @return array<string, mixed>
     */
    private function continueWhile(int $id, string $phase): array
    {
        for ($step = 0; $step < self::MOST_STEPS; $step++) {
            $response = $this->postJson("/api/admin/imports/{$id}/continue");
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            ['import' => $import, 'stepped' => $stepped] = $this->decode($response);
            self::assertTrue($stepped, 'nothing else on it: the step ran');
            if ($import['phase'] !== $phase) {
                return $import;
            }
        }

        self::fail("Import #{$id} stayed {$phase} after " . self::MOST_STEPS . ' steps.');
    }

    /** A panel of the fake bot on the fake connector; one that cannot be added has no connection kept. */
    private static function panel(string $key, string $url, int $services = 1, bool $addable = true): ImportedPanel
    {
        return new ImportedPanel($key, strtoupper($key), 'x-ui_single', $url, FakeProvider::driver(), null, $addable ? ['base_url' => $url, 'api_token' => 't'] : [], $services);
    }
}
