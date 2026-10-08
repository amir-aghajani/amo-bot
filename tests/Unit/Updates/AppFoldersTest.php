<?php

declare(strict_types=1);

namespace Tests\Unit\Updates;

use App\Modules\Updates\AppFolders;
use App\Modules\Updates\Disk;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Tests\TestCase;

/**
 * The app's own folders and files swapped for a release's in a scratch app, and back: each folder moved whole, each
 * file replaced in place, the app's own kept aside, config.php and storage/ never touched — and both ways picking up
 * wherever a crash left them, read off the disk itself.
 */
final class AppFoldersTest extends TestCase
{
    private const PATHS = ['.htaccess', 'app', 'bootstrap', 'public', 'vendor'];

    private string $app;

    private string $release;

    private string $previous;

    private AppFolders $folders;

    protected function setUp(): void
    {
        parent::setUp();

        $root = $this->scratchDir();
        $this->app = "{$root}/shop";
        $this->release = "{$root}/updates/0.2.0";
        $this->previous = "{$root}/updates/previous";
        $this->write($this->app, ['.htaccess' => 'old', 'app/code.php' => 'old', 'bootstrap/app.php' => 'old', 'public/index.php' => 'old', 'vendor/autoload.php' => 'old', 'config.php' => 'the shop\'s', 'storage/logs/app.log' => 'the shop\'s']);
        $this->write($this->release, ['.htaccess' => 'new', 'app/code.php' => 'new', 'app/new.php' => 'new', 'bootstrap/app.php' => 'new', 'public/index.php' => 'new', 'vendor/autoload.php' => 'new', 'composer.json' => 'new']);
        $this->folders = new AppFolders($this->app, $this->previous);
    }

    public function testAnInstallPutsTheReleaseInPlaceAndKeepsTheAppsOwnAside(): void
    {
        $this->folders->install($this->release, [...self::PATHS, 'composer.json']);

        self::assertSame(['new', 'new', 'new', 'new', 'new'], [$this->read('.htaccess'), $this->read('app/code.php'), $this->read('app/new.php'), $this->read('public/index.php'), $this->read('composer.json')]);
        self::assertSame("the shop's", $this->read('config.php'), 'config.php is never a release\'s');
        self::assertSame("the shop's", $this->read('storage/logs/app.log'), 'nor storage/');
        self::assertSame('old', file_get_contents("{$this->previous}/app/code.php"), 'the version it replaced, kept');
        self::assertSame('old', file_get_contents("{$this->previous}/.htaccess"));
        self::assertFileDoesNotExist("{$this->previous}/composer.json", 'the app had none to keep');
        self::assertTrue($this->folders->kept());
    }

    public function testWhatTheHostsToolsWroteIntoTheHtaccessFilesGoesOnIntoTheReleases(): void
    {
        // cPanel's MultiPHP Manager keeps the PHP version in the document root's .htaccess, its INI Editor PHP's settings.
        $handler = "# php -- BEGIN cPanel-generated handler, do not edit\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php83 .php\n</IfModule>\n# php -- END cPanel-generated handler, do not edit";
        $ini = "# BEGIN cPanel-generated php ini directives, do not edit\n<IfModule php8_module>\n   php_value memory_limit 256M\n</IfModule>\n# END cPanel-generated php ini directives, do not edit";
        $this->write($this->app, ['.htaccess' => "old rules\n\n{$handler}\n", 'public/.htaccess' => "{$ini}\r\nold public rules\n"]);
        $this->write($this->release, ['public/.htaccess' => "new public rules\n"]);

        $this->folders->install($this->release, self::PATHS);
        $this->folders->install($this->release, self::PATHS);

        self::assertSame("new\n\n{$handler}\n", $this->read('.htaccess'), 'the release\'s rules, the host\'s PHP version kept — once');
        self::assertSame("new public rules\n\n{$ini}\n", $this->read('public/.htaccess'));
        self::assertSame("old rules\n\n{$handler}\n", file_get_contents("{$this->previous}/.htaccess"), 'the old file as it was, kept aside');
    }

    public function testATakeBackPutsTheAppsOwnBackAndTheReleaseInItsFolder(): void
    {
        $paths = [...self::PATHS, 'composer.json'];
        $this->folders->install($this->release, $paths);

        $this->folders->restore($this->release, $paths);

        self::assertSame(['old', 'old', 'old'], [$this->read('.htaccess'), $this->read('app/code.php'), $this->read('public/index.php')]);
        self::assertFileDoesNotExist("{$this->app}/app/new.php");
        self::assertFileDoesNotExist("{$this->app}/composer.json", 'what the release brought goes with it');
        self::assertSame('new', file_get_contents("{$this->release}/app/new.php"), 'the release whole again, for another try');
        self::assertSame('new', file_get_contents("{$this->release}/composer.json"));
        self::assertFalse($this->folders->kept(), 'nothing aside any more');
    }

    public function testAnInstallACrashCutShortIsFinishedFromWhereItStopped(): void
    {
        // It had moved the app's app/ aside, and not yet the release's in.
        Disk::folder($this->previous);
        Disk::move("{$this->app}/app", "{$this->previous}/app");

        $this->folders->install($this->release, self::PATHS);

        self::assertSame(['new', 'new', 'new'], [$this->read('app/code.php'), $this->read('public/index.php'), $this->read('vendor/autoload.php')]);
        self::assertSame('old', file_get_contents("{$this->previous}/app/code.php"));
    }

    public function testAnInstallACrashCutShortIsTakenBackFromWhereItStopped(): void
    {
        // app/ swapped, public/ moved aside, vendor/ untouched.
        $this->folders->install($this->release, ['app']);
        Disk::move("{$this->app}/public", "{$this->previous}/public");

        $this->folders->restore($this->release, self::PATHS);

        self::assertSame(['old', 'old', 'old', 'old'], [$this->read('.htaccess'), $this->read('app/code.php'), $this->read('public/index.php'), $this->read('vendor/autoload.php')]);
        self::assertSame(['new', 'new'], [file_get_contents("{$this->release}/app/code.php"), file_get_contents("{$this->release}/public/index.php")]);
    }

    public function testAPathWithNowhereToGoStopsTheInstall(): void
    {
        $this->write($this->previous, ['app/code.php' => 'from an update never cleared']);

        try {
            $this->folders->install($this->release, self::PATHS);
            self::fail('The app\'s app/ had nowhere to go.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('app', $e->getMessage());
        }
        self::assertSame('old', $this->read('app/code.php'), 'nothing of the app\'s was lost');
    }

    public function testWhetherPhpMayChangeTheAppsFoldersIsAskedOfTheDisk(): void
    {
        self::assertNull($this->folders->unchangeable(self::PATHS), 'the test\'s own folders');
        self::assertTrue($this->folders->renames(), 'one file system');
        self::assertSame(['app', 'bootstrap', 'public', 'vendor'], array_values(array_diff(scandir($this->app) ?: [], ['.', '..', '.htaccess', 'config.php', 'storage'])), 'the probe left nothing behind');
        self::assertFalse($this->folders->isCheckout());

        mkdir("{$this->app}/.git");
        self::assertTrue($this->folders->isCheckout(), 'a clone of the repository: git updates it');
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testAFolderPhpMayNotWriteIsSaidByName(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root writes any folder.');
        }
        chmod("{$this->app}/vendor", 0o555);

        try {
            self::assertSame('vendor', $this->folders->unchangeable(self::PATHS), 'moving it aside needs it writable');
        } finally {
            chmod("{$this->app}/vendor", 0o755);
        }
    }

    /** @param array<string, string> $files */
    private function write(string $folder, array $files): void
    {
        foreach ($files as $path => $content) {
            Disk::folder(dirname("{$folder}/{$path}"));
            file_put_contents("{$folder}/{$path}", $content);
        }
    }

    private function read(string $path): string
    {
        return (string) file_get_contents("{$this->app}/{$path}");
    }
}
