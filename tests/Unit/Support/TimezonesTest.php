<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Timezones;
use PHPUnit\Framework\TestCase;

final class TimezonesTest extends TestCase
{
    public function testEveryOfferedIdIsOnePhpAccepts(): void
    {
        $known = array_flip(\DateTimeZone::listIdentifiers());

        foreach (Timezones::zones() as $zone) {
            self::assertArrayHasKey($zone['id'], $known, $zone['id']);
            self::assertMatchesRegularExpression('/\p{Arabic}/u', $zone['name'], 'names are Persian');
            self::assertDoesNotMatchRegularExpression('/[\x{064B}-\x{0655}\x{06C0}]/u', $zone['name'], 'no diacritics');
        }

        self::assertGreaterThan(100, count(Timezones::zones()));
    }

    public function testLabelsAreWindowsStyle(): void
    {
        self::assertSame('(UTC+03:30) وقت ایران', Timezones::label(12600, 'وقت ایران'));
        self::assertSame('(UTC-03:30) x', Timezones::label(-12600, 'x'));
        self::assertSame('(UTC) زمان جهانی هماهنگ', Timezones::label(0, 'زمان جهانی هماهنگ'));
    }

    public function testListIsSortedByOffsetAndStartsWithTheCanonicalIds(): void
    {
        $offsets = array_column(Timezones::zones(), 'offset');
        $sorted = $offsets;
        sort($sorted);

        self::assertSame($sorted, $offsets);
        self::assertContains('Asia/Tehran', array_column(Timezones::options(), 'id'));
    }

    public function testAStoredAliasStandsInForItsGroup(): void
    {
        $options = Timezones::options('Europe/Rome');
        $ids = array_column($options, 'id');

        self::assertContains('Europe/Rome', $ids);
        self::assertNotContains('Europe/Berlin', $ids, 'the group is represented once, by the stored id');
        self::assertStringStartsWith('(UTC+01:00) وقت مرکز اروپا', $options[array_search('Europe/Rome', $ids, true)]['label']);

        $unknown = Timezones::options('Antarctica/Troll');
        self::assertSame('Antarctica/Troll', $unknown[0]['id'], 'an id outside every group is still selectable');
    }
}
