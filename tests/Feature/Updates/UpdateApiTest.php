<?php

declare(strict_types=1);

namespace Tests\Feature\Updates;

use App\Core\Installation;
use App\Core\Support\FileLock;
use App\Modules\Updates\AppFolders;
use App\Modules\Updates\Manifest;
use App\Modules\Updates\Updater;
use App\Modules\Updates\Workspace;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\FakeGitHub;
use Tests\Support\FakeRelease;

/**
 * The owner's one-press update, end to end on a shop of the test's own: the newest release found, begun, taken a step
 * a request — its signed manifest believed, its zip checked against it and unpacked, the host checked — and installed in
 * one request while the shop is held: the app's folders the release's, the shop's own (config.php, storage/) untouched,
 * the database brought along, the version recorded; or, anything after the swap failing, the swap taken back. Nothing
 * unsigned, nothing other than what was signed, and nothing from elsewhere than GitHub is installed.
 */
#[RequiresPhpExtension('sodium')]
#[RequiresPhpExtension('zip')]
final class UpdateApiTest extends UpdateTestCase
{
    private const SCREEN = '/api/admin/system/update';

    public function testTheScreenSaysTheVersionTheNewestReleaseAndItsNotes(): void
    {
        $this->release()->publish($this->gitHub, '0.2.0', null, "Faster checkouts.\r\n\r\n- A fix <b>here</b>");

        $update = $this->screen($this->get(self::SCREEN));

        self::assertSame('0.1.0', $update['current']);
        self::assertSame([
            'version' => '0.2.0',
            'published_at' => '2026-10-01T09:30:00+00:00',
            'notes' => "Faster checkouts.\n\n- A fix <b>here</b>",
            'url' => 'https://github.com/amir-aghajani/amo-bot/releases/tag/v0.2.0',
        ], $update['latest'], 'the notes as plain text — the panel shows them as they are');
        self::assertTrue($update['available']);
        self::assertNull($update['blocker']);
        self::assertNull($update['run']);
        self::assertNotNull($update['checked_at']);

        self::assertSame(['GET ' . FakeGitHub::API], $this->gitHub->calls());
        self::assertSame('AmoBot/0.1.0', $this->gitHub->request(0)->getHeaderLine('User-Agent'), 'a User-Agent naming AmoBot and its version');
        $this->get(self::SCREEN);
        self::assertCount(1, $this->gitHub->calls(), 'read again only once a few hours have passed: GitHub takes 60 calls an hour from an address');
    }

    public function testAnUpdateGoesAStepARequestAndInstallsTheReleaseWithTheShopHeld(): void
    {
        $this->release()->publish($this->gitHub, '0.2.0', FakeRelease::files('0.2.0', ['database/upgrades/0.2.0.php' => $this->upgrade()]));

        $started = $this->screen($this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']));
        self::assertSame(['version' => '0.2.0', 'from' => '0.1.0', 'step' => 'download', 'progress' => 0, 'error' => null, 'cancel' => true, 'rollback' => false, 'finished_at' => null], $started['run']);
        self::assertFalse($started['available'], 'one update at a time');

        $screen = $this->stepUntil('done');
        $done = $screen['run'];

        self::assertFalse($screen['available'], 'installed: nothing more to offer before the page reloads on the new code');
        self::assertSame(['0.2.0', 100, null, false, false], [$done['version'], $done['progress'], $done['error'], $done['cancel'], $done['rollback']], 'it changed the database: only a backup takes it back');
        self::assertNotNull($done['finished_at']);
        self::assertSame(['0.2.0', "# 0.2.0\n", '<p>0.2.0</p>'], [$this->shopFile('app/marker.txt'), $this->shopFile('.htaccess'), $this->shopFile('public/admin/index.html')], 'the release in the app\'s place');
        self::assertSame("<?php return ['APP_NAME' => 'The shop'];", $this->shopFile('config.php'), 'the shop\'s configuration untouched');
        self::assertSame('a customer\'s receipt', $this->shopFile('storage/uploads/receipts/7.jpg'), 'and its files');
        self::assertSame('0.1.0', file_get_contents("{$this->work}/previous/app/marker.txt"), 'the version it replaced, kept until the next update');
        self::assertSame('0.2.0', $this->service(Installation::class)->version(), 'the version recorded');
        self::assertSame(['held'], $this->db()->table('update_probe')->pluck('shop')->all(), 'the database upgraded while every other request was told to come back');
        self::assertFileDoesNotExist($this->flag, 'and the shop open again');
        self::assertFileDoesNotExist("{$this->routes}/routes-old.php", 'the router\'s table of the old routes forgotten');
        self::assertDirectoryDoesNotExist("{$this->work}/download", 'the downloads gone');
        self::assertDirectoryDoesNotExist("{$this->work}/0.2.0");

        $files = ['release.json', 'release.json.sig', 'amobot-0.2.0.zip'];
        self::assertSame(['GET ' . FakeGitHub::API, ...array_merge(...array_map(static fn(string $name): array => [
            'GET https://github.com/amir-aghajani/amo-bot/releases/download/v0.2.0/' . $name,
            'GET https://' . FakeGitHub::STORAGE . "/github-production-release-asset/{$name}?sig=x",
        ], $files))], $this->gitHub->calls(), 'each file from github.com, through its redirect to GitHub\'s storage');
        self::assertFalse($this->gitHub->options(1)['allow_redirects'], 'every redirect looked at before it is followed');
    }

    public function testAnUpdateThatChangedNothingOfTheDatabaseIsTakenBack(): void
    {
        $this->release()->publish($this->gitHub, '0.2.0');
        $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);
        self::assertTrue($this->stepUntil('done')['run']['rollback']);

        $response = $this->postJson(self::SCREEN . '/rollback');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('rolled_back', $this->screen($response)['run']['step']);
        self::assertSame(['0.1.0', "# 0.1.0\n"], [$this->shopFile('app/marker.txt'), $this->shopFile('.htaccess')], 'the version it replaced in its place again');
        self::assertSame('0.1.0', $this->service(Installation::class)->version());
        self::assertFileDoesNotExist($this->flag);
        self::assertDirectoryDoesNotExist("{$this->work}/previous/app", 'nothing kept aside any more');
        self::assertSame(422, $this->postJson(self::SCREEN . '/rollback')->getStatusCode(), 'once');
    }

    public function testAnUpdateThatChangedTheDatabaseIsTakenBackOnlyWithABackup(): void
    {
        $this->release()->publish($this->gitHub, '0.2.0', FakeRelease::files('0.2.0', ['database/upgrades/0.2.0.php' => $this->upgrade()]));
        $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);
        $this->stepUntil('done');

        $response = $this->postJson(self::SCREEN . '/rollback');

        self::assertSame([422, Updater::DATABASE_CHANGED], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame('0.2.0', $this->shopFile('app/marker.txt'));
    }

    public function testAnInstallWhoseDatabaseUpgradeFailsIsTakenBackAndSaysWhy(): void
    {
        $failing = '<?php return static function (): void { throw new RuntimeException("Duplicate column name \'badge\'"); };';
        $this->release()->publish($this->gitHub, '0.2.0', FakeRelease::files('0.2.0', ['database/upgrades/0.2.0.php' => $failing]));
        $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);
        $this->stepUntil('install');

        $response = $this->postJson(self::SCREEN . '/step');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame("به‌روزرسانی دیتابیس به نسخه 0.2.0 انجام نشد: Duplicate column name 'badge'. فایل‌های برنامه به نسخه 0.1.0 برگشت؛ دوباره امتحان کنید.", $this->decode($response)['message']);
        self::assertSame(['0.1.0', "# 0.1.0\n"], [$this->shopFile('app/marker.txt'), $this->shopFile('.htaccess')], 'the swap taken back');
        self::assertSame('0.1.0', $this->service(Installation::class)->version());
        self::assertFileDoesNotExist($this->flag, 'the shop open again');

        $run = $this->screen($this->get(self::SCREEN))['run'];
        self::assertSame(['install', $this->decode($response)['message'], true], [$run['step'], $run['error'], $run['cancel']], 'said with the run, for another try — or giving it up');
        self::assertSame(200, $this->postJson(self::SCREEN . '/cancel')->getStatusCode());
        self::assertNull($this->screen($this->get(self::SCREEN))['run']);
        self::assertDirectoryDoesNotExist("{$this->work}/0.2.0", 'what it fetched and unpacked gone');
    }

    public function testAnInstallACrashCutShortIsFinishedByTheNextStep(): void
    {
        $this->release()->publish($this->gitHub, '0.2.0', FakeRelease::files('0.2.0', ['database/upgrades/0.2.0.php' => $this->upgrade()]));
        $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);
        $this->stepUntil('install');
        // The install's request swapped the app's folders and died before its upgrades.
        $workspace = new Workspace($this->work);
        $run = $workspace->run();
        self::assertNotNull($run);
        $workspace->save($run->installing(true, FakeRelease::PATHS));
        (new AppFolders($this->folder, $workspace->previous()))->install($workspace->release('0.2.0'), FakeRelease::PATHS);
        self::assertFalse($this->screen($this->get(self::SCREEN))['run']['cancel'], 'the app\'s files are the release\'s already');

        $done = $this->stepUntil('done');

        self::assertSame('0.2.0', $done['run']['version']);
        self::assertSame('0.2.0', $this->service(Installation::class)->version());
        self::assertSame(['held'], $this->db()->table('update_probe')->pluck('shop')->all());
        self::assertSame('0.1.0', file_get_contents("{$this->work}/previous/app/marker.txt"));
    }

    public function testFilesPutInPlaceByHandHaveTheirDatabaseBroughtAlong(): void
    {
        // 0.1.0 uploaded over a shop whose database is 0.0.9's.
        $this->service(Installation::class)->moveTo('0.0.9');
        $this->place($this->folder, ['database/upgrades/0.1.0.php' => $this->upgrade()]);

        $waiting = $this->screen($this->get(self::SCREEN))['run'];
        self::assertSame(['version' => '0.1.0', 'from' => '0.0.9', 'step' => 'install', 'progress' => 0, 'error' => null, 'cancel' => false, 'rollback' => false, 'finished_at' => null], $waiting);

        $done = $this->stepUntil('done')['run'];

        self::assertFalse($done['rollback'], 'nothing was swapped, nothing is kept to put back');
        self::assertSame('0.1.0', $this->service(Installation::class)->version());
        self::assertSame(['held'], $this->db()->table('update_probe')->pluck('shop')->all());
        self::assertSame("# 0.1.0\n", $this->shopFile('.htaccess'), 'the files stay as they were put');
    }

    public function testFilesByHandWhoseUpgradeFailsAreUpgradedOnTheNextTry(): void
    {
        $this->service(Installation::class)->moveTo('0.0.9');
        $once = "{$this->folder}/storage/fail-once";
        file_put_contents($once, '');
        $this->place($this->folder, ['database/upgrades/0.1.0.php' => sprintf(
            '<?php return static function (): void { if (is_file(%1$s)) { unlink(%1$s); throw new RuntimeException("Lock wait timeout exceeded"); } };',
            var_export($once, true),
        )]);

        $failed = $this->postJson(self::SCREEN . '/step');

        self::assertSame(422, $failed->getStatusCode());
        self::assertSame('به‌روزرسانی دیتابیس به نسخه 0.1.0 انجام نشد: Lock wait timeout exceeded. دوباره امتحان کنید.', $this->decode($failed)['message']);
        $run = $this->screen($this->get(self::SCREEN))['run'];
        self::assertSame(['install', false], [$run['step'], $run['cancel']], 'still the upgrades the files wait for: nothing to give up');
        self::assertSame('0.0.9', $this->service(Installation::class)->version());

        self::assertSame('done', $this->stepUntil('done')['run']['step'], 'tried again, the upgrade goes');
        self::assertSame('0.1.0', $this->service(Installation::class)->version());
    }

    public function testAReleaseSignedByAnotherKeyIsNeverInstalled(): void
    {
        $this->release();
        $zip = FakeRelease::zip('0.2.0', FakeRelease::files('0.2.0'));
        $manifest = FakeRelease::manifest('0.2.0', $zip);
        // A hijacked account publishes a release of its own, signed with a key of its own.
        $this->gitHub->publish('0.2.0', ['amobot-0.2.0.zip' => $zip, 'release.json' => $manifest, 'release.json.sig' => (new FakeRelease())->sign($manifest)]);
        $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);

        $response = $this->postJson(self::SCREEN . '/step');

        self::assertSame([422, Manifest::UNSIGNED], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertNotContains('GET https://github.com/amir-aghajani/amo-bot/releases/download/v0.2.0/amobot-0.2.0.zip', $this->gitHub->calls(), 'its zip never fetched');
        self::assertDirectoryDoesNotExist("{$this->work}/download", 'nothing unsigned kept');
        self::assertSame(['download', Manifest::UNSIGNED], [$this->screen($this->get(self::SCREEN))['run']['step'], $this->screen($this->get(self::SCREEN))['run']['error']]);
        self::assertSame('0.1.0', $this->shopFile('app/marker.txt'));
    }

    public function testAZipOtherThanTheOneSignedIsNeverUnpacked(): void
    {
        $zip = FakeRelease::zip('0.2.0', FakeRelease::files('0.2.0'));
        $manifest = FakeRelease::manifest('0.2.0', $zip);
        // The same size, a byte of it another.
        $other = substr_replace($zip, chr(ord($zip[100]) ^ 1), 100, 1);
        $this->gitHub->publish('0.2.0', ['amobot-0.2.0.zip' => $other, 'release.json' => $manifest, 'release.json.sig' => $this->release()->sign($manifest)]);
        $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);

        $response = $this->postJson(self::SCREEN . '/step');

        self::assertSame([422, Updater::ZIP_MISMATCH], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertFileDoesNotExist("{$this->work}/download/amobot-0.2.0.zip");
    }

    public function testAZipBiggerThanItsManifestSaysIsNotTakenWhole(): void
    {
        $zip = FakeRelease::zip('0.2.0', FakeRelease::files('0.2.0'));
        $manifest = FakeRelease::manifest('0.2.0', $zip);
        $this->gitHub->publish('0.2.0', ['amobot-0.2.0.zip' => $zip . str_repeat('x', 100000), 'release.json' => $manifest, 'release.json.sig' => $this->release()->sign($manifest)]);
        $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);

        $response = $this->postJson(self::SCREEN . '/step');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('فایل amobot-0.2.0.zip بزرگ‌تر از چیزی است که این نسخه گفته؛ نصب نمی‌شود.', $this->decode($response)['message']);
        self::assertSame([], glob("{$this->work}/download/amobot-0.2.0.zip*") ?: [], 'not a byte of it kept');
    }

    public function testAFileGitHubSendsElsewhereIsNotFetched(): void
    {
        $this->release()->publish($this->gitHub, '0.2.0');
        $this->gitHub->storeOn('downloads.example.com');
        $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);

        $response = $this->postJson(self::SCREEN . '/step');

        self::assertSame([422, 'GitHub فایل release.json را به آدرسی بیرون از خودش فرستاد؛ دانلود نشد.'], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertNotContains('GET https://downloads.example.com/github-production-release-asset/release.json?sig=x', $this->gitHub->calls());
    }

    public function testAHostTheReleaseOutgrowsIsSaidSoBeforeAnythingIsReplaced(): void
    {
        foreach ([[['php' => '99.0'], 'PHP 99.0'], [['extensions' => ['json', 'an_extension_nobody_has']], 'an_extension_nobody_has']] as [$needs, $said]) {
            $zip = FakeRelease::zip('0.2.0', FakeRelease::files('0.2.0'));
            $manifest = FakeRelease::manifest('0.2.0', $zip, $needs);
            $this->gitHub->publish('0.2.0', ['amobot-0.2.0.zip' => $zip, 'release.json' => $manifest, 'release.json.sig' => $this->release()->sign($manifest)]);
            $this->postJson(self::SCREEN . '/cancel');
            $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);
            $this->stepUntil('preflight');

            $response = $this->postJson(self::SCREEN . '/step');

            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString($said, $this->decode($response)['message']);
            self::assertSame('0.1.0', $this->shopFile('app/marker.txt'));
        }
    }

    public function testAShopThatCannotCheckAReleaseIsUpdatedByHand(): void
    {
        $this->gitHub->publish('0.2.0');

        $update = $this->screen($this->get(self::SCREEN));
        self::assertSame([true, Updater::NO_KEY], [$update['available'], $update['blocker']], 'no release key of its own: a build made without one');
        $start = $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);
        self::assertSame([422, Updater::NO_KEY], [$start->getStatusCode(), $this->decode($start)['message']]);

        $this->release();
        self::assertSame(sprintf('نسخه %s فایل‌های امضاشده‌ای را که به‌روزرسانی از داخل پنل لازم دارد ندارد؛ دستی به‌روز کنید (راهنمای Upgrading).', '0.2.0'), $this->screen($this->get(self::SCREEN))['blocker'], 'a release without its signed files');

        mkdir("{$this->folder}/.git");
        self::assertSame(Updater::CHECKOUT, $this->screen($this->get(self::SCREEN))['blocker'], 'a clone of the repository');
    }

    public function testOnlyTheNewestReleaseNewerThanTheShopsIsBegunAndOneAtATime(): void
    {
        $this->release()->publish($this->gitHub, '0.2.0');

        $other = $this->postJson(self::SCREEN . '/start', ['version' => '0.3.0']);
        self::assertSame([422, ['version' => [Updater::NOT_LATEST]]], [$other->getStatusCode(), $this->decode($other)['errors']]);

        $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);
        $again = $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);
        self::assertSame([409, Updater::UNDER_WAY], [$again->getStatusCode(), $this->decode($again)['message']]);

        // Another request works the update this moment.
        $lock = FileLock::take("{$this->work}/state.lock");
        try {
            $busy = $this->postJson(self::SCREEN . '/step');
            self::assertSame([409, Workspace::BUSY], [$busy->getStatusCode(), $this->decode($busy)['message']]);
        } finally {
            $lock?->release();
        }
    }

    public function testAnInstallWaitsForTheSchedulersRunAndDoesNotOutwaitIt(): void
    {
        $this->release()->publish($this->gitHub, '0.2.0');
        $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);
        $this->stepUntil('install');
        $scheduler = FileLock::take($this->schedulerLock);

        try {
            $response = $this->postJson(self::SCREEN . '/step');
        } finally {
            $scheduler?->release();
        }

        self::assertSame([409, Updater::SCHEDULER_BUSY], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame('0.1.0', $this->shopFile('app/marker.txt'), 'nothing swapped');
        self::assertFileDoesNotExist($this->flag, 'the shop never held');
        self::assertSame('done', $this->stepUntil('done')['run']['step'], 'once it ended, the install goes');
    }

    public function testARunIsGivenUpBeforeItsInstallAndNotAfter(): void
    {
        $this->release()->publish($this->gitHub, '0.2.0');
        $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);
        $this->stepUntil('extract');

        $cancelled = $this->postJson(self::SCREEN . '/cancel');

        self::assertSame(200, $cancelled->getStatusCode());
        self::assertNull($this->screen($cancelled)['run']);
        self::assertDirectoryDoesNotExist("{$this->work}/download", 'what it fetched gone');
        self::assertSame(422, $this->postJson(self::SCREEN . '/cancel')->getStatusCode(), 'nothing left to give up');

        $this->postJson(self::SCREEN . '/start', ['version' => '0.2.0']);
        $this->stepUntil('done');
        $done = $this->postJson(self::SCREEN . '/cancel');
        self::assertSame([422, Updater::NO_RUN], [$done->getStatusCode(), $this->decode($done)['message']], 'an installed update is taken back, not given up');
    }

    public function testGitHubOutOfReachIsSaidAndTheReleaseReadLastStands(): void
    {
        $this->release()->publish($this->gitHub, '0.2.0');
        $this->get(self::SCREEN);
        $this->gitHub->down();

        $response = $this->postJson(self::SCREEN . '/check');

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('GitHub جواب نداد؛ کمی بعد دوباره امتحان کنید.', $this->decode($response)['message']);
        self::assertSame('0.2.0', $this->screen($this->get(self::SCREEN))['latest']['version']);
    }

    public function testTheUpdateIsTheOwnersAlone(): void
    {
        $this->release()->publish($this->gitHub, '0.2.0');
        $_SESSION = [];
        self::assertSame(401, $this->get(self::SCREEN)->getStatusCode(), 'signed out');

        $this->loginAsAgent($this->agentBot());
        self::assertSame(404, $this->unchecked()->get('/api/agent/system/update')->getStatusCode(), 'an agent\'s panel has no such address');
        self::assertSame(404, $this->unchecked()->postJson('/api/agent/system/update/start', ['version' => '0.2.0'])->getStatusCode());

        $_SESSION = [];
        $website = $this->website(['staff_grants' => []]);
        $this->loginAsStaff($website, $this->customer(['telegram_id' => 7002]));
        self::assertSame(404, $this->unchecked()->get($this->storeApi($website, '/admin/system/update'))->getStatusCode(), 'nor the shop\'s admins on its website');
        self::assertSame([], array_filter($this->gitHub->calls(), static fn(string $call): bool => str_contains($call, '/download/')), 'and nothing was fetched for any of them');
    }

    /** The database upgrade a test's release ships: it writes down whether the shop was held while it ran. */
    private function upgrade(): string
    {
        return sprintf(
            '<?php return static function (Illuminate\Database\Schema\Builder $schema, Illuminate\Database\Connection $db): void {
                $schema->create("update_probe", static function (Illuminate\Database\Schema\Blueprint $table): void { $table->string("shop"); });
                $db->table("update_probe")->insert(["shop" => is_file(%s) ? "held" : "open"]);
            };',
            var_export($this->flag, true),
        );
    }
}
