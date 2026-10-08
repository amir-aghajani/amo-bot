<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config\ConfigFile;
use App\Core\Config\ConfigKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * config.php, the shop's own configuration: read as the app boots on it, written whole — every declared setting in its
 * section with its words, the ones the file does not set shown commented out at their defaults, a driver's DB_*
 * settings after DB_CONNECTION, anything else kept —, any value surviving the round trip as what it is.
 */
final class ConfigFileTest extends TestCase
{
    private string $dir;

    private ConfigFile $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = $this->scratchDir();
        $this->file = new ConfigFile($this->dir . '/config.php', $this->dir . '/cache/config.lock');
    }

    public function testNoFileIsNoSettingsAndWritingMakesIt(): void
    {
        self::assertSame([], $this->file->all());
        self::assertFalse($this->file->exists());
        self::assertTrue($this->file->isWritable(), 'its folder takes it');

        $this->file->set('APP_NAME', 'فروشگاه من');

        self::assertTrue($this->file->exists());
        self::assertSame(['APP_NAME' => 'فروشگاه من'], $this->file->all());
        self::assertSame('فروشگاه من', $this->file->get('APP_NAME'));
        self::assertNull($this->file->get('APP_URL'), 'what it does not set is null here; ConfigValues gives its default');
    }

    /** @return iterable<string, array{0: string|int|float|bool}> */
    public static function values(): iterable
    {
        yield 'quotes and backslashes' => ['it\'s "a" \\ path\\'];
        yield 'what PHP would read as code' => ['${x} {$y} <?php ?> */ /* // #'];
        yield 'a line break' => ["one\ntwo"];
        yield 'Persian with a ZWNJ' => ['می‌شود'];
        yield 'empty' => [''];
        yield 'a number' => [120];
        yield 'a fraction' => [1.5];
        yield 'on' => [true];
        yield 'off' => [false];
    }

    #[DataProvider('values')]
    public function testAnyValueSurvivesTheRoundTripAsWhatItIs(string|int|float|bool $value): void
    {
        $this->file->setMany(['APP_NAME' => $value, 'OTHER_THING' => $value]);

        self::assertSame($value, $this->file->get('APP_NAME'));
        self::assertSame($value, $this->file->get('OTHER_THING'), 'one of no section\'s too');
    }

    public function testTheFileIsLaidOutByItsSectionsWithTheDefaultsItDoesNotSetShown(): void
    {
        $this->file->setMany(['DB_PASSWORD' => 'secret', 'MY_OWN' => 'kept', 'APP_KEY' => 'base64:k', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'localhost', 'APP_NAME' => 'Shop']);
        $text = (string) file_get_contents($this->file->path());

        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n\n/*\n", $text);
        self::assertStringContainsString('wp-config.php', $text, 'it says what it is');
        $needles = ['// ---- The shop', "'APP_NAME' => 'Shop',", '// ---- Database', "'DB_CONNECTION' => 'mysql',", "'DB_PASSWORD' => 'secret',", "'DB_HOST' => 'localhost',", '// ---- Keys and secrets', "'APP_KEY' => 'base64:k',", '// ---- Other settings', "'MY_OWN' => 'kept',"];
        $positions = array_map(static fn(string $needle): int|false => strpos($text, $needle), $needles);
        self::assertNotContains(false, $positions, 'each is there');
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'each where it belongs, a driver\'s settings after DB_CONNECTION in the order they were written');
        self::assertStringContainsString("    // 'APP_TIMEZONE' => 'UTC',\n", $text, 'a setting it does not set: at its default, commented out');
        self::assertStringContainsString("    // 'SESSION_SECURE_COOKIE' => false,\n", $text);
        self::assertStringContainsString("    // 'SESSION_LIFETIME' => 120,\n", $text);
        foreach (explode("\n", $text) as $line) {
            self::assertLessThanOrEqual(120, mb_strlen($line), "within the file's width: {$line}");
        }
        self::assertSame(self::sorted(['DB_PASSWORD' => 'secret', 'MY_OWN' => 'kept', 'APP_KEY' => 'base64:k', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'localhost', 'APP_NAME' => 'Shop']), self::sorted($this->file->all()));
    }

    public function testEveryDeclaredSettingIsShownWhetherSetOrNot(): void
    {
        $this->file->set('APP_NAME', 'Shop');
        $text = (string) file_get_contents($this->file->path());

        foreach (ConfigKeys::SECTIONS as $settings) {
            foreach (array_keys($settings) as $key) {
                self::assertStringContainsString("'{$key}' =>", $text, $key);
            }
        }
        self::assertSame(['APP_NAME' => 'Shop'], $this->file->all(), 'the commented ones set nothing');
    }

    public function testAWriteKeepsWhatItDoesNotChange(): void
    {
        $this->file->setMany(['APP_NAME' => 'Shop', 'DB_PASSWORD' => 'p', 'HAND_WRITTEN' => 'x']);

        $this->file->setMany(['APP_NAME' => 'Shop 2', 'APP_DEBUG' => true]);

        self::assertSame(self::sorted(['APP_NAME' => 'Shop 2', 'DB_PASSWORD' => 'p', 'HAND_WRITTEN' => 'x', 'APP_DEBUG' => true]), self::sorted($this->file->all()));
    }

    public function testAFileEditedByHandIsReadAsItSaysAndKeptAsItSaysOnTheNextWrite(): void
    {
        file_put_contents($this->file->path(), "<?php\n// mine\nreturn ['APP_NAME' => 'By hand', 'DB_PORT' => 3307, 'LIST' => ['a'], 7 => 'no name'];\n");

        self::assertSame(['APP_NAME' => 'By hand', 'DB_PORT' => 3307, 'LIST' => ['a']], $this->file->all(), 'a setting has a name');

        $this->file->set('APP_URL', 'https://shop.example');

        self::assertSame(self::sorted(['APP_NAME' => 'By hand', 'DB_PORT' => 3307, 'LIST' => ['a'], 'APP_URL' => 'https://shop.example']), self::sorted($this->file->all()));
    }

    public function testAFileThatDoesNotParseStopsWhoeverReadsIt(): void
    {
        file_put_contents($this->file->path(), "<?php\nreturn ['APP_NAME' => 'Shop'\n");

        $this->expectException(\ParseError::class);
        $this->file->all();
    }

    public function testAFileThatReturnsNoSettingsIsRefused(): void
    {
        file_put_contents($this->file->path(), "<?php\nreturn 'APP_NAME=Shop';\n");

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("must return the shop's settings");
        $this->file->all();
    }

    public function testAFileTheServerMayNotWriteIsSaidAndLeftAsItWas(): void
    {
        // A folder stands where config.php is written.
        mkdir($this->file->path());

        try {
            $this->file->set('APP_NAME', 'Shop');
            self::fail('written over a folder');
        } catch (\RuntimeException) {
            self::assertDirectoryExists($this->file->path());
        }
        self::assertSame([], glob($this->dir . '/*.tmp') ?: [], 'no half-written file left beside it');
    }

    public function testAFolderThatTakesNoFileIsNoPlaceToWrite(): void
    {
        $file = new ConfigFile($this->dir . '/no-such-folder/config.php', $this->dir . '/config.lock');

        self::assertFalse($file->isWritable());
        $this->expectException(\RuntimeException::class);
        $file->set('APP_NAME', 'Shop');
    }

    /**
     * Settings by name, whatever order the file lists them in (its sections').
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function sorted(array $settings): array
    {
        ksort($settings);

        return $settings;
    }
}
