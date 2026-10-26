<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PhpVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The PHP releases the shop runs on (Support\PhpVersion): of each supported branch its release without the known
 * security holes and every one after; nothing of a branch past PHP's support, nor of PHP 8.2 (no branch of the shop's
 * since 0.7.1); any of a branch newer than every listed one. composer.json and CI name the same.
 */
final class PhpVersionTest extends TestCase
{
    /** @return iterable<string, array{string, bool, string}> A host's PHP, whether the shop runs on it, the release to name */
    public static function hosts(): iterable
    {
        yield 'a branch\'s release with the holes' => ['8.3.10', false, '8.3.36'];
        yield 'its first without them' => ['8.3.36', true, '8.3.36'];
        yield 'a later one' => ['8.4.30', true, '8.4.27'];
        yield 'the oldest branch\'s release before its fix' => ['8.3.35', false, '8.3.36'];
        yield 'a build of a fixed release' => ['8.5.12-dev', true, '8.5.12'];
        yield 'PHP 8.2, though PHP still patches it' => ['8.2.35', false, '8.3.36'];
        yield 'a branch past PHP\'s support' => ['8.1.34', false, '8.3.36'];
        yield 'a branch newer than every listed one' => ['8.6.0', true, '8.3.36'];
    }

    #[DataProvider('hosts')]
    public function testAHostsPhpIsOneTheShopRunsOnFromItsBranchsFixedRelease(string $php, bool $safe, string $required): void
    {
        self::assertSame($safe, PhpVersion::isSafe($php), $php);
        self::assertSame($required, PhpVersion::required($php), $php);
    }

    public function testAReleaseThatNamesNoMinimumsTakesAnyPhp(): void
    {
        self::assertTrue(PhpVersion::isSafe('8.2.0', []), 'a release made before 0.7.1 named only the oldest PHP');
        self::assertFalse(PhpVersion::isSafe('8.3.10', ['8.3' => '8.3.11']), 'one that names its own, by them');
        self::assertSame('8.3.11', PhpVersion::required('8.3.10', ['8.3' => '8.3.11']));
    }

    public function testComposerJsonAndCiNameTheSamePhp(): void
    {
        $composer = json_decode((string) file_get_contents($this->app()->basePath('composer.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('~8.3.36 || ~8.4.27 || >=8.5.12', PhpVersion::composer());
        self::assertSame(PhpVersion::composer(), $composer['require']['php'], 'composer.json requires what PhpVersion lists');
        self::assertSame(array_values(PhpVersion::MINIMUMS)[0], $composer['config']['platform']['php'], 'the packages are chosen for the oldest PHP the shop runs on');

        preg_match('/php: \\[([^\\]]+)\\]/', (string) file_get_contents($this->app()->basePath('.github/workflows/ci.yml')), $matrix);
        $branches = array_map(static fn(string $branch): string => trim($branch, " '"), explode(',', $matrix[1] ?? ''));
        self::assertSame(array_keys(PhpVersion::MINIMUMS), $branches, 'CI runs the suite on every branch the shop runs on, and no other');
    }

    public function testTheHostIsTheRealPhpUnlessATestPlaysAnother(): void
    {
        self::assertSame(self::HOST_PHP, PhpVersion::host(), 'every test plays a PHP without known holes');
        PhpVersion::setTestHost(null);
        self::assertSame(PHP_VERSION, PhpVersion::host());
    }
}
