<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The time-zone picker's list: Windows-style groups ("(UTC+03:30) وقت ایران") of IANA ids, from the data file
 * scripts/timezones.php generates (so the app needs no intl). APP_TIMEZONE stores an IANA id.
 */
final class Timezones
{
    public const DATA_FILE = 'app/Support/data/timezones.php';

    /** @var list<array{id: string, offset: int, name: string, ids: list<string>}>|null */
    private static ?array $zones = null;

    /**
     * The picker's options, the stored id always among them even when it is not its group's first (Europe/Rome shows
     * under its group's name).
     *
     * @return list<array{id: string, label: string}>
     */
    public static function options(?string $current = null): array
    {
        $options = [];
        $seen = false;

        foreach (self::zones() as $zone) {
            $label = self::label($zone['offset'], $zone['name']);
            if ($current !== null && $current !== $zone['id'] && in_array($current, $zone['ids'], true)) {
                $options[] = ['id' => $current, 'label' => $label];
                $seen = true;
                continue; // the stored alias stands in for its group
            }
            $seen = $seen || $current === $zone['id'];
            $options[] = ['id' => $zone['id'], 'label' => $label];
        }

        if ($current !== null && $current !== '' && !$seen) {
            try {
                $offset = (new \DateTimeZone($current))->getOffset(new \DateTimeImmutable('2026-01-15'));
                array_unshift($options, ['id' => $current, 'label' => self::label($offset, $current)]);
            } catch (\Exception) {
                // an unknown id: the validator will refuse it anyway
            }
        }

        return $options;
    }

    /** "(UTC+03:30) وقت ایران" — the offset in Latin digits like everywhere else, the name in Persian. */
    public static function label(int $offset, string $name): string
    {
        if ($offset === 0) {
            return "(UTC) {$name}";
        }

        $sign = $offset < 0 ? '-' : '+';
        $hours = intdiv(abs($offset), 3600);
        $minutes = intdiv(abs($offset) % 3600, 60);

        return sprintf('(UTC%s%02d:%02d) %s', $sign, $hours, $minutes, $name);
    }

    /** @return list<array{id: string, offset: int, name: string, ids: list<string>}> */
    public static function zones(): array
    {
        return self::$zones ??= require dirname(__DIR__, 2) . '/' . self::DATA_FILE;
    }
}
