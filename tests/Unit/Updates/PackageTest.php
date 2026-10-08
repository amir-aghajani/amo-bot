<?php

declare(strict_types=1);

namespace Tests\Unit\Updates;

use App\Modules\Updates\Exceptions\UpdateRefusedException;
use App\Modules\Updates\Manifest;
use App\Modules\Updates\Package;
use App\Modules\Updates\ReleaseKey;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\FakeRelease;
use Tests\TestCase;

/**
 * A release's zip unpacked with nothing taken beyond what its manifest signed: every entry under the zip's top folder and
 * inside the release's own paths — none climbing out, none absolute, none another drive's or spelled with a backslash
 * (zip-slip), no link — checked whole before a byte is written; then unpacked a slice at a time, as a host's time limit
 * allows, the shop's storage/ left out.
 */
#[RequiresPhpExtension('sodium')]
#[RequiresPhpExtension('zip')]
final class PackageTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = $this->scratchDir() . '/0.2.0';
    }

    public function testAReleaseIsUnpackedASliceAtATimeItsStorageLeftOut(): void
    {
        $package = $this->package(FakeRelease::zip('0.2.0', FakeRelease::files('0.2.0')));
        $package->check();

        // Time is up at once: an entry a call, picked up where the last one stopped.
        $next = 0;
        for ($calls = 1; ($next = $package->extract($this->folder, $next, 0.0)) < $package->count(); $calls++) {
            self::assertLessThan(100, $calls);
        }

        self::assertSame($package->count(), $calls, 'one entry a call');
        self::assertSame('0.2.0', file_get_contents("{$this->folder}/app/marker.txt"));
        self::assertSame('<p>0.2.0</p>', file_get_contents("{$this->folder}/public/admin/index.html"));
        self::assertSame("# 0.2.0\n", file_get_contents("{$this->folder}/.htaccess"));
        self::assertDirectoryDoesNotExist("{$this->folder}/storage", 'the shop\'s own storage/ is never the release\'s');
        self::assertSame($package->count(), $package->extract($this->folder, 0, microtime(true) + 60), 'once more, all at once: it is the same');
    }

    public function testAnEntryThatWouldGoAnywhereElseRefusesTheZipBeforeAByteIsWritten(): void
    {
        $outside = [
            '!amobot-0.2.0/../evil.php' => 'climbing out of the release',
            '!amobot-0.2.0/app/../../evil.php' => 'climbing out from inside it',
            '!/etc/evil' => 'an absolute path',
            '!C:/evil.php' => 'another drive',
            '!amobot-0.2.0\\..\\evil.php' => 'a backslash, a separator on Windows',
            '!amobot-0.1.0/app/evil.php' => 'another release\'s top folder',
            '!evil.php' => 'no top folder',
            'config.php' => 'the shop\'s configuration',
            '.git/config' => 'a path the manifest does not name',
            'app//evil.php' => 'an empty segment',
        ];
        foreach ($outside as $entry => $why) {
            $package = $this->package(FakeRelease::zip('0.2.0', [...FakeRelease::files('0.2.0'), $entry => '<?php evil();']));

            try {
                $package->check();
                self::fail("Taken: {$why}.");
            } catch (UpdateRefusedException $e) {
                self::assertSame(Package::NOT_A_RELEASE, $e->getMessage(), $why);
            }
            self::assertDirectoryDoesNotExist($this->folder, $why);
        }
    }

    public function testALinkIsNoEntryOfARelease(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'amobot-release');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        foreach (FakeRelease::files('0.2.0') as $path => $content) {
            $zip->addFromString("amobot-0.2.0/{$path}", $content);
        }
        $zip->addFromString('amobot-0.2.0/app/link', '/etc/passwd');
        // A Unix symbolic link, as `zip --symlinks` stores one.
        $zip->setExternalAttributesName('amobot-0.2.0/app/link', \ZipArchive::OPSYS_UNIX, (0o120777 << 16));
        $zip->close();
        $bytes = (string) file_get_contents($file);
        unlink($file);

        $this->expectExceptionObject(UpdateRefusedException::refused(Package::NOT_A_RELEASE));
        $this->package($bytes)->check();
    }

    public function testWhatIsNoZipIsUnreadable(): void
    {
        $this->expectExceptionObject(UpdateRefusedException::refused(Package::UNREADABLE));
        $this->package('PK but no zip')->count();
    }

    /** The package of the zip `$bytes`, with a manifest signed for them. */
    private function package(string $bytes): Package
    {
        $release = new FakeRelease();
        $folder = $this->scratchDir();
        file_put_contents("{$folder}/release-key.pub", $release->keyFile());
        file_put_contents("{$folder}/amobot-0.2.0.zip", $bytes);
        $json = FakeRelease::manifest('0.2.0', $bytes);

        return new Package("{$folder}/amobot-0.2.0.zip", Manifest::verified($json, $release->sign($json), new ReleaseKey("{$folder}/release-key.pub"), '0.2.0'));
    }
}
