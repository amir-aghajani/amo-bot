<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config\ConfigKeys;
use App\Core\Config\ConfigValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * config.php as the app reads it: each setting as its type, the default for one the file does not set or sets to
 * something it cannot be — a hand writes «'120'» and «'false'» as often as 120 and false —, by the rules the settings
 * screen reads the file with, and a driver's DB_* settings as written.
 */
final class ConfigValuesTest extends TestCase
{
    public function testASettingTheFileDoesNotSetIsItsDefault(): void
    {
        $values = new ConfigValues([]);

        self::assertSame('AmoBot', $values->string('APP_NAME'));
        self::assertSame(120, $values->int('SESSION_LIFETIME'));
        self::assertFalse($values->bool('APP_DEBUG'));
        self::assertSame(ConfigKeys::default('DB_CONNECTION'), $values->string('DB_CONNECTION'));
    }

    public function testASettingIsReadAsItsTypeHoweverItIsWritten(): void
    {
        $values = new ConfigValues([
            'APP_NAME' => 'Shop',
            'SESSION_LIFETIME' => ' 60 ',
            'LOG_MAX_FILES' => '۷',
            'OUTGOING_HTTP_TIMEOUT' => 15.0,
            'TELEGRAM_POLL_TIMEOUT' => 25,
            'APP_DEBUG' => 'TRUE',
            'SESSION_SECURE_COOKIE' => 1,
            'TELEGRAM_BOT_USERNAME' => 12345,
        ]);

        self::assertSame('Shop', $values->string('APP_NAME'));
        self::assertSame(60, $values->int('SESSION_LIFETIME'));
        self::assertSame(7, $values->int('LOG_MAX_FILES'), 'Persian digits are a number too');
        self::assertSame(15, $values->int('OUTGOING_HTTP_TIMEOUT'));
        self::assertSame(25, $values->int('TELEGRAM_POLL_TIMEOUT'));
        self::assertTrue($values->bool('APP_DEBUG'));
        self::assertTrue($values->bool('SESSION_SECURE_COOKIE'));
        self::assertSame('12345', $values->string('TELEGRAM_BOT_USERNAME'));
    }

    public function testAValueASettingCannotBeIsItsDefault(): void
    {
        $values = new ConfigValues([
            'SESSION_LIFETIME' => 'two hours',
            'LOG_MAX_FILES' => 1.5,
            'OUTGOING_HTTP_TIMEOUT' => '-5',
            'APP_NAME' => ['Shop'],
            'APP_URL' => true,
        ]);

        self::assertSame(120, $values->int('SESSION_LIFETIME'));
        self::assertSame(14, $values->int('LOG_MAX_FILES'));
        self::assertSame(30, $values->int('OUTGOING_HTTP_TIMEOUT'));
        self::assertSame('AmoBot', $values->string('APP_NAME'));
        self::assertSame('http://localhost', $values->string('APP_URL'));
    }

    /** @return iterable<string, array{0: mixed, 1: bool}> */
    public static function switches(): iterable
    {
        foreach ([true, 1, '1', 'true', 'TRUE'] as $on) {
            yield 'on: ' . var_export($on, true) => [$on, true];
        }
        foreach ([false, 0, '0', 'false', 'False'] as $off) {
            yield 'off: ' . var_export($off, true) => [$off, false];
        }
    }

    #[DataProvider('switches')]
    public function testASwitchIsReadAsTheSettingsScreenReadsOne(mixed $written, bool $read): void
    {
        self::assertSame($read, (new ConfigValues(['SESSION_SECURE_COOKIE' => $written]))->bool('SESSION_SECURE_COOKIE'));
        self::assertSame($read, (new ConfigValues(['APP_DEBUG' => $written]))->bool('APP_DEBUG'));
    }

    public function testWhatIsNoSwitchIsTheSettingsDefault(): void
    {
        foreach (['on', 'yes', 'maybe', '', 2] as $written) {
            self::assertFalse((new ConfigValues(['APP_DEBUG' => $written]))->bool('APP_DEBUG'), var_export($written, true));
        }
    }

    public function testADriversSettingsAreItsOwnAsWritten(): void
    {
        $values = new ConfigValues(['DB_PASSWORD' => 'null', 'DB_SOCKET' => 'true', 'DB_PORT' => 3307, 'DB_LIST' => ['x'], 'APP_NAME' => 'Shop']);

        self::assertSame(['DB_PASSWORD' => 'null', 'DB_SOCKET' => 'true', 'DB_PORT' => '3307'], $values->prefixed('DB_'), 'as text and uncoerced: a password may read "null"; nothing that is no text');
    }

    public function testASettingNobodyDeclaredIsTheCodesMistake(): void
    {
        $this->expectException(\LogicException::class);
        (new ConfigValues([]))->string('APP_NAEM');
    }
}
