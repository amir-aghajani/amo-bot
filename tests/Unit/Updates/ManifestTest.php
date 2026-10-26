<?php

declare(strict_types=1);

namespace Tests\Unit\Updates;

use App\Modules\Updates\Exceptions\UpdateRefusedException;
use App\Modules\Updates\Manifest;
use App\Modules\Updates\ReleaseKey;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\FakeRelease;
use Tests\TestCase;

/**
 * A release's manifest is believed only once the key built into the shop signed its very bytes, and only as the release
 * asked for: another key's signature, a byte changed, no key at all, or a manifest of another version — or one that
 * would replace the shop's data (config.php, storage/) — is refused before anything in it is read.
 */
#[RequiresPhpExtension('sodium')]
final class ManifestTest extends TestCase
{
    private FakeRelease $release;

    private ReleaseKey $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->release = new FakeRelease();
        file_put_contents($file = $this->scratchDir() . '/release-key.pub', $this->release->keyFile());
        $this->key = new ReleaseKey($file);
    }

    public function testASignedManifestIsTheReleasesWord(): void
    {
        $zip = 'the zip';
        $json = FakeRelease::manifest('0.2.0', $zip, ['php' => '8.3', 'extensions' => ['bcmath', 'pdo_mysql']]);

        $manifest = Manifest::verified($json, $this->release->sign($json), $this->key, '0.2.0');

        self::assertSame(['0.2.0', 'amobot-0.2.0.zip', strlen($zip), hash('sha256', $zip), '8.3'], [$manifest->version, $manifest->file, $manifest->size, $manifest->sha256, $manifest->php]);
        self::assertSame(['bcmath', 'pdo_mysql'], $manifest->extensions);
        self::assertSame(FakeRelease::PATHS, $manifest->paths);
        self::assertSame([], $manifest->phpMinimums, 'a release made before 0.7.1 names none');

        $json = FakeRelease::manifest('0.2.0', $zip, ['php' => '8.3.36', 'php_minimums' => ['8.3' => '8.3.36', '8.4' => '8.4.27']]);
        self::assertSame(['8.3' => '8.3.36', '8.4' => '8.4.27'], Manifest::verified($json, $this->release->sign($json), $this->key, '0.2.0')->phpMinimums);
    }

    public function testOnlyTheKeysSignatureOverTheVeryBytesIsBelieved(): void
    {
        $json = FakeRelease::manifest('0.2.0', 'the zip');

        $this->assertRefused(Manifest::UNSIGNED, $json, (new FakeRelease())->sign($json), 'another key signed it: a hijacked account\'s');
        $this->assertRefused(Manifest::UNSIGNED, str_replace('8.3', '8.4', $json), $this->release->sign($json), 'a byte of it changed after it was signed');
        $this->assertRefused(Manifest::UNSIGNED, $json, 'bm90IGEgc2lnbmF0dXJl', 'no signature at all');
        $this->assertRefused(Manifest::UNSIGNED, $json, '', 'an empty one');

        file_put_contents($empty = $this->scratchDir() . '/release-key.pub', '');
        $this->expectExceptionObject(UpdateRefusedException::refused(Manifest::UNSIGNED));
        Manifest::verified($json, $this->release->sign($json), new ReleaseKey($empty), '0.2.0');
    }

    public function testASignedManifestOfAnotherReleaseIsNoUpdate(): void
    {
        // 0.1.0, signed by the key long ago — offered as 0.2.0.
        $old = FakeRelease::manifest('0.1.0', 'the old zip');

        $this->assertRefused(Manifest::NOT_THIS_RELEASE, $old, $this->release->sign($old), 'its signed version is not the one asked for');
    }

    public function testASignedManifestThatWouldReplaceTheShopsOwnIsRefused(): void
    {
        $cases = [
            'config.php among its paths' => ['paths' => [...FakeRelease::PATHS, 'config.php']],
            'storage/ among them' => ['paths' => [...FakeRelease::PATHS, 'storage']],
            'a path climbing out' => ['paths' => [...FakeRelease::PATHS, '..']],
            'a path deeper than the top' => ['paths' => [...FakeRelease::PATHS, 'app/Core']],
            'a hidden one' => ['paths' => [...FakeRelease::PATHS, '.git']],
            'no vendor/' => ['paths' => array_values(array_diff(FakeRelease::PATHS, ['vendor']))],
            'a zip of another name' => ['file' => 'amobot-0.2.0-patched.zip'],
            'a size no zip has' => ['size' => 0],
            'one bigger than a release is' => ['size' => Manifest::MAX_BYTES + 1],
            'a sha256 that is none' => ['sha256' => 'abc'],
            'a PHP that is no version' => ['php' => 'latest'],
            'PHP releases that are no list of them' => ['php_minimums' => 'all'],
            'a branch\'s release of another branch' => ['php_minimums' => ['8.3' => '8.4.1']],
            'a branch that is none' => ['php_minimums' => ['latest' => '8.3.1']],
            'an extension that is no name' => ['extensions' => ['bcmath; rm -rf /']],
        ];
        foreach ($cases as $why => $overrides) {
            $json = FakeRelease::manifest('0.2.0', 'the zip', $overrides);

            $this->assertRefused(Manifest::NOT_THIS_RELEASE, $json, $this->release->sign($json), $why);
        }
    }

    private function assertRefused(string $message, string $json, string $signature, string $why): void
    {
        try {
            Manifest::verified($json, $signature, $this->key, '0.2.0');
            self::fail("Believed: {$why}.");
        } catch (UpdateRefusedException $e) {
            self::assertSame([$message, 422], [$e->getMessage(), $e->status()], $why);
        }
    }
}
