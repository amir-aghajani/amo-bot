<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Telegram\Admin\Sections\Panel\Search\PanelSettings;
use App\Modules\Telegram\Admin\Sections\Panel\Search\SettingsIndex;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use Tests\BotTestCase;

/**
 * Every setting the panel has, the bot has too — or says why the panel alone sets it: the panel's settings search index
 * (resources/panel/src/lib/settings-search/settings.generated.ts, read here as the panel's build reads it) is what the
 * bot's copy (PanelSettings) holds, word for word; every section of it has a screen of the bot's (SettingsIndex), and
 * every place there names a screen the management has, a field that card has, a part it has. A setting the panel adds
 * fails here until the bot has a place for it.
 */
final class SettingsParityTest extends BotTestCase
{
    private const GENERATED = 'resources/panel/src/lib/settings-search/settings.generated.ts';

    /**
     * The bot's fields the panel's index lists no setting for (`screen.field`): the extra devices' price, whose label the
     * panel draws at run time; the phone check's other moments, one choice group with «کارهایی که شماره می‌خواهند»
     * (phone_on_purchase); the lottery prize's amount, drawn under «جایزه هر برنده» by the prize picked.
     */
    private const UNINDEXED = [
        'bsx.extra_devices_price',
        'bsg.phone_on_renewal',
        'bsg.phone_on_test',
        'bsg.phone_on_card',
        'lts.lottery_wallet',
        'lts.lottery_traffic_gb',
        'lts.lottery_days',
    ];

    public function testTheBotsCopyOfThePanelsIndexIsThePanelsWordForWord(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::GENERATED);
        $panel = self::parse($source);
        $settings = array_sum(array_map(static fn(array $section): int => count($section['settings']), $panel));

        // Parsed whole: as many settings as the file names keys, as many sections as it has addresses.
        self::assertSame(preg_match_all("/\bkey: '/", $source), $settings, 'every setting of the file read');
        self::assertSame(preg_match_all("/^  '\\//m", $source), count($panel), 'every section of the file read');
        self::assertGreaterThan(200, $settings);

        $copy = [];
        foreach (PanelSettings::SECTIONS as $address => $section) {
            $copy[$address] = ['hint' => $section['hint'], 'alias' => $section['alias'], 'settings' => $section['settings']];
        }
        self::assertSame($panel, $copy, 'PanelSettings.php is not settings.generated.ts: run `pnpm settings:index`.');
    }

    public function testEverySettingOfThePanelsHasAPlaceInTheBot(): void
    {
        $sections = self::constant('SECTIONS');
        $places = self::constant('SETTINGS');
        $screens = $this->service(Screens::class);

        foreach (PanelSettings::SECTIONS as $address => $section) {
            self::assertArrayHasKey($address, $sections, "The panel's section {$address} has no place in the bot: add it to SettingsIndex::SECTIONS.");
            $keys = array_column($section['settings'], 'key');
            foreach (array_keys($places[$address] ?? []) as $key) {
                self::assertContains($key, $keys, "SettingsIndex::SETTINGS names {$address}#{$key}, a setting the panel has not.");
            }
        }
        foreach (array_keys($sections) as $address) {
            self::assertArrayHasKey($address, PanelSettings::SECTIONS, "SettingsIndex::SECTIONS names {$address}, a section the panel has not.");
        }

        foreach (SettingsIndex::entries() as $entry) {
            $where = "«{$entry->title}» ({$entry->key})";
            self::assertNotSame('', $entry->screen, "{$where} has no screen in the bot.");
            // A button Telegram carries (Nav: no part it would mangle, 64 bytes at most) — else an owner's search throws.
            self::assertStringStartsWith(Nav::PREFIX . $entry->screen . ':', (string) ($entry->button('x')->markup()['callback_data'] ?? ''), $where);
            $screen = $screens->get($entry->screen);
            self::assertNotNull($screen, "{$where} opens «{$entry->screen}», a screen the management has not.");
            if ($entry->field !== null || $entry->part !== null) {
                self::assertInstanceOf(SettingsScreen::class, $screen, "{$where} opens a field or a part of «{$entry->screen}», which is no settings card.");
            }
            if ($entry->field !== null) {
                $fields = array_map(static fn(FieldDef $field): string => $field->name, $this->call($screen, 'fields'));
                self::assertContains($entry->field, $fields, "{$where} opens the field «{$entry->field}», which «{$entry->screen}» has not.");
            }
            if ($entry->part !== null) {
                self::assertArrayHasKey($entry->part, $this->call($screen, 'parts'), "{$where} opens part {$entry->part} of «{$entry->screen}», which it has not.");
            }
        }
    }

    /**
     * Where the bot has a section as a settings card, the parity is proven field by field, both ways: a setting in one of
     * the panel's cards (or a part of one) has its own place — its field, its part, another screen or why the panel alone
     * sets it —, so a setting the panel adds to a card fails here rather than open the card unnoticed; and every field
     * of the bot's card is found by some setting of the panel's, but the few the panel's index cannot list (UNINDEXED).
     */
    public function testInEveryCardTheBotDrawsEachSettingHasItsOwnPlaceAndEachFieldIsFound(): void
    {
        $sections = self::constant('SECTIONS');
        $places = self::constant('SETTINGS');
        $screens = $this->service(Screens::class);
        $found = [];
        foreach (SettingsIndex::entries() as $entry) {
            if ($entry->field !== null) {
                $found[$entry->screen][] = $entry->field;
            }
        }

        $cards = 0;
        foreach (PanelSettings::SECTIONS as $address => $section) {
            $id = (string) ($sections[$address][0] ?? '');
            $screen = $screens->get($id);
            if (!$screen instanceof SettingsScreen) {
                continue;
            }
            $cards++;
            foreach ($section['settings'] as $setting) {
                // A card's own heading opens the card itself; what is in it, its own place.
                if ($setting['card'] !== '' || $setting['part'] !== '') {
                    self::assertArrayHasKey($setting['key'], $places[$address] ?? [], "{$address}#{$setting['key']} («{$setting['title']}») is in a card the bot has as «{$id}» but has no place of its own: add it to SettingsIndex::SETTINGS.");
                }
            }
            $names = array_map(static fn(FieldDef $field): string => $field->name, $this->call($screen, 'fields'));
            foreach ($names as $name) {
                if (in_array("{$id}.{$name}", self::UNINDEXED, true)) {
                    self::assertNotContains($name, $found[$id] ?? [], "{$id}.{$name} is found now: take it out of UNINDEXED.");

                    continue;
                }
                self::assertContains($name, $found[$id] ?? [], "The field «{$name}» of «{$id}» is found by no setting of the panel's: name it in SettingsIndex::SETTINGS.");
            }
        }
        self::assertGreaterThan(20, $cards, 'the bot\'s cards checked');
        foreach (self::UNINDEXED as $unindexed) {
            [$id, $name] = explode('.', $unindexed, 2);
            $screen = $screens->get($id);
            self::assertInstanceOf(SettingsScreen::class, $screen, $unindexed);
            self::assertContains($name, array_map(static fn(FieldDef $field): string => $field->name, $this->call($screen, 'fields')), "{$unindexed}: a field the card has not");
        }
    }

    public function testWhatThePanelAloneSetsSaysWhy(): void
    {
        $only = array_filter(SettingsIndex::entries(), static fn($entry): bool => $entry->only !== '');
        $titles = array_map(static fn($entry): string => $entry->title, $only);

        foreach (['رمز عبور جدید', 'تکرار رمز عبور جدید', 'تم'] as $title) {
            self::assertContains($title, $titles, "«{$title}»: the panel's alone, said so");
        }
        foreach ($only as $entry) {
            self::assertGreaterThan(20, mb_strlen($entry->only), "«{$entry->title}»: why, in a sentence");
        }
    }

    /**
     * A constant of SettingsIndex's, as the parity is checked against it.
     *
     * @return array<string, mixed>
     */
    private static function constant(string $name): array
    {
        $value = (new \ReflectionClassConstant(SettingsIndex::class, $name))->getValue();

        return is_array($value) ? $value : [];
    }

    /**
     * A card's protected hook, called as SettingsScreen calls it — on a card made afresh, as the main shop's owner opens
     * it: the run's own was last drawn for whichever seat an earlier test was (an agent's card has fewer fields).
     *
     * @return array<int|string, mixed>
     */
    private function call(object $screen, string $method): array
    {
        $fresh = $this->app()->container()->make($screen::class);
        $value = (new \ReflectionMethod($fresh, $method))->invoke($fresh);

        return is_array($value) ? $value : [];
    }

    /**
     * settings.generated.ts's GENERATED_SETTINGS, as the bot's copy keeps it: each address, its hint and alias ('' for
     * none), and its settings with every field written (key, title, hint, card, part).
     *
     * @return array<string, array{hint: string, alias: string, settings: list<array{key: string, title: string, hint: string, card: string, part: string}>}>
     */
    private static function parse(string $source): array
    {
        $start = strpos($source, '= {', (int) strpos($source, 'export const GENERATED_SETTINGS'));
        self::assertNotFalse($start, 'GENERATED_SETTINGS is not in the file.');
        $at = $start + 2;
        $object = self::value($source, $at);
        self::assertIsArray($object);

        $sections = [];
        foreach ($object as $address => $section) {
            self::assertIsArray($section);
            $settings = [];
            foreach (is_array($section['settings'] ?? null) ? $section['settings'] : [] as $setting) {
                self::assertIsArray($setting);
                $settings[] = ['key' => (string) $setting['key'], 'title' => (string) $setting['title'], 'hint' => (string) ($setting['hint'] ?? ''), 'card' => (string) ($setting['card'] ?? ''), 'part' => (string) ($setting['part'] ?? '')];
            }
            $sections[(string) $address] = ['hint' => (string) ($section['hint'] ?? ''), 'alias' => (string) ($section['alias'] ?? ''), 'settings' => $settings];
        }

        return $sections;
    }

    /** One value of the object literal at `$at`: an object, an array or a string — what the generated file is made of. */
    private static function value(string $source, int &$at): mixed
    {
        self::space($source, $at);
        $char = $source[$at];
        if ($char === '{' || $char === '[') {
            $object = $char === '{';
            $close = $object ? '}' : ']';
            $at++;
            $items = [];
            while (true) {
                self::space($source, $at);
                if ($source[$at] === $close) {
                    $at++;

                    return $items;
                }
                if ($object) {
                    $key = $source[$at] === "'" || $source[$at] === '"' ? self::string($source, $at) : self::bare($source, $at);
                    self::space($source, $at);
                    self::assertSame(':', $source[$at], "a «:» at {$at}");
                    $at++;
                    $items[$key] = self::value($source, $at);
                } else {
                    $items[] = self::value($source, $at);
                }
                self::space($source, $at);
                if ($source[$at] === ',') {
                    $at++;
                }
            }
        }

        return self::string($source, $at);
    }

    /** A quoted string at `$at` (single or double quotes, JavaScript's escapes). */
    private static function string(string $source, int &$at): string
    {
        $quote = $source[$at];
        self::assertContains($quote, ["'", '"'], "a string at {$at}");
        $at++;
        $text = '';
        while ($source[$at] !== $quote) {
            if ($source[$at] === '\\') {
                $next = $source[$at + 1];
                if ($next === 'u') {
                    $braced = $source[$at + 2] === '{';
                    $hex = $braced ? substr($source, $at + 3, (int) strpos($source, '}', $at) - $at - 3) : substr($source, $at + 2, 4);
                    $text .= mb_chr((int) hexdec($hex), 'UTF-8');
                    $at += $braced ? strlen($hex) + 4 : 6;

                    continue;
                }
                $text .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    default => $next,
                };
                $at += 2;

                continue;
            }
            $text .= $source[$at];
            $at++;
        }
        $at++;

        return $text;
    }

    /** An unquoted key at `$at`. */
    private static function bare(string $source, int &$at): string
    {
        self::assertSame(1, preg_match('/\G[A-Za-z_$][A-Za-z0-9_$]*/', $source, $match, 0, $at), "a key at {$at}");
        $at += strlen($match[0]);

        return $match[0];
    }

    /** Past spaces and line breaks. */
    private static function space(string $source, int &$at): void
    {
        while (ctype_space($source[$at])) {
            $at++;
        }
    }
}
