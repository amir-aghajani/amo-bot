<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Requirements;
use Tests\TestCase;

final class RequirementsTest extends TestCase
{
    public function testTheListNamesEverythingComposerRequiresTheDatabaseDriversExtensionAndWhatTheShopWrites(): void
    {
        $checks = array_column($this->service(Requirements::class)->check(), 'ok', 'name');

        self::assertSame(
            ['ext-pdo_sqlite', 'ext-curl', 'ext-mbstring', 'ext-openssl', 'ext-bcmath', 'ext-fileinfo', 'memory_limit >= 64M', 'storage/ writable', 'config.php writable', 'ext-sodium', 'ext-zip'],
            array_keys($checks),
            'the suite runs on SQLite: its driver\'s extension; PHP\'s version is Composer\'s platform check',
        );
        self::assertTrue($checks['ext-bcmath'], 'Money is bcmath');
        self::assertTrue($checks['ext-fileinfo'], 'the receipt endpoint sniffs the content type');
        self::assertTrue($checks['memory_limit >= 64M'], 'this machine runs the suite: it has the memory');
        self::assertTrue($checks['storage/ writable']);
        self::assertTrue($checks['config.php writable'], 'the config.php the app writes — the run\'s own here, not there yet: its folder takes it');
    }

    public function testWhatThePanelsOwnUpdateNeedsIsShownAndHoldsNothingUp(): void
    {
        $optional = array_column($this->service(Requirements::class)->check(), 'optional', 'name');
        $met = ['name' => 'ext-curl', 'label' => 'curl', 'ok' => true, 'optional' => false];

        self::assertSame(['ext-sodium', 'ext-zip'], array_keys(array_filter($optional)), 'the release\'s signature checked, its zip unpacked: composer.json suggests them');
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
