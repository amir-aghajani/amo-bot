<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PhpVersion;
use App\Support\Requirements;
use Tests\TestCase;

final class RequirementsTest extends TestCase
{
    public function testTheListNamesEverythingComposerRequiresTheDatabaseDriversExtensionAndWhatTheShopWrites(): void
    {
        $checks = array_column($this->service(Requirements::class)->check(), 'ok', 'name');

        self::assertSame(
            ['php', 'ext-pdo_sqlite', 'ext-curl', 'ext-mbstring', 'ext-openssl', 'ext-bcmath', 'ext-fileinfo', 'memory_limit >= 64M', 'storage/ writable', 'config.php writable', 'ext-sodium', 'ext-zip', 'ext-gd'],
            array_keys($checks),
            'a PHP without its known security holes first; the suite runs on SQLite: its driver\'s extension',
        );
        self::assertTrue($checks['php'], 'the suite plays a host on a PHP without them');
        self::assertTrue($checks['ext-bcmath'], 'Money is bcmath');
        self::assertTrue($checks['ext-fileinfo'], 'the receipt endpoint sniffs the content type');
        self::assertTrue($checks['memory_limit >= 64M'], 'this machine runs the suite: it has the memory');
        self::assertTrue($checks['storage/ writable']);
        self::assertTrue($checks['config.php writable'], 'the config.php the app writes — the run\'s own here, not there yet: its folder takes it');
    }

    public function testAPhpWithItsBranchsKnownSecurityHolesHoldsTheInstallUpAndSaysWhichReleaseToMoveTo(): void
    {
        PhpVersion::setTestHost('8.3.10');

        $checks = $this->service(Requirements::class)->check();
        $php = array_column($checks, null, 'name')['php'];

        self::assertFalse($php['ok']);
        self::assertFalse($php['optional']);
        self::assertSame('PHP 8.3.36 یا بالاتر؛ نسخه‌های قدیمی‌تر حفره امنیتی دارند (PHP این هاست: 8.3.10)', $php['label']);
        self::assertFalse(Requirements::met($checks), 'the installer does not go on');
    }

    public function testWhatThePanelsOwnUpdateNeedsIsShownAndHoldsNothingUp(): void
    {
        $optional = array_column($this->service(Requirements::class)->check(), 'optional', 'name');
        $met = ['name' => 'ext-curl', 'label' => 'curl', 'ok' => true, 'optional' => false];

        self::assertSame(['ext-sodium', 'ext-zip', 'ext-gd'], array_keys(array_filter($optional)), 'the release\'s signature checked, its zip unpacked, the QR card drawn: composer.json suggests them');
        $suggested = array_keys((array) (json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR)['suggest'] ?? []));
        $shown = array_keys(array_filter($optional));
        sort($suggested);
        sort($shown);
        self::assertSame($suggested, $shown, 'every extension composer.json suggests, and no other');
        self::assertTrue(Requirements::met([$met, ['name' => 'ext-zip', 'label' => 'zip', 'ok' => false, 'optional' => true]]), 'an optional one missing holds nothing up');
        self::assertFalse(Requirements::met([$met, ['name' => 'ext-bcmath', 'label' => 'bcmath', 'ok' => false, 'optional' => false]]));
    }

    public function testEveryRequirementIsWordedForTheInstallerTheDriversExtensionWithTheDriversName(): void
    {
        $labels = array_column($this->service(Requirements::class)->check(), 'label', 'name');

        self::assertSame('افزونه pdo_sqlite، برای اتصال به SQLite', $labels['ext-pdo_sqlite']);
        self::assertSame('اجازه نوشتن در پوشه storage', $labels['storage/ writable']);
        self::assertNotContains('', $labels);
    }
}
