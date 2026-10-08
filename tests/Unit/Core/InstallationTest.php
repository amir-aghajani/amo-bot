<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Application;
use App\Core\Installation;
use Tests\TestCase;

/**
 * The installation's lock: written as the installer ends, saying when and which version installed the shop — the
 * version its database is at, which every upgrade and update moves on, keeping when the shop was installed.
 */
final class InstallationTest extends TestCase
{
    public function testTheInstallerRecordsTheCodesVersion(): void
    {
        $installation = new Installation($lock = $this->scratchDir() . '/installed.lock');
        self::assertFalse($installation->isInstalled());

        $installation->markInstalled();

        self::assertTrue($installation->isInstalled());
        self::assertSame(Application::VERSION, $installation->version());
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\S+ ' . preg_quote(Application::VERSION, '/') . '$/', trim((string) file_get_contents($lock)));
    }

    public function testAnUpgradeMovesTheVersionAndKeepsWhenTheShopWasInstalled(): void
    {
        file_put_contents($lock = $this->scratchDir() . '/installed.lock', "2026-09-17T14:26:14+00:00 0.1.0\n");
        $installation = new Installation($lock);

        $installation->moveTo('0.2.0');

        self::assertSame('0.2.0', $installation->version());
        self::assertSame('2026-09-17T14:26:14+00:00 0.2.0', trim((string) file_get_contents($lock)));
    }

    public function testALockThatNamesNoVersionIsTheFirstReleases(): void
    {
        // Written before the lock said the version — and by Windows, with its line ending.
        file_put_contents($lock = $this->scratchDir() . '/installed.lock', "2026-09-17T14:26:14+00:00\r\n");
        $installation = new Installation($lock);

        self::assertSame(Installation::FIRST_RELEASE, $installation->version());

        $installation->moveTo('0.2.0');
        self::assertSame('2026-09-17T14:26:14+00:00 0.2.0', trim((string) file_get_contents($lock)));
    }
}
