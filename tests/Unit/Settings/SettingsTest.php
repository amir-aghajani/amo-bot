<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Exceptions\UnknownGroupException;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Settings\Services\Settings;
use Tests\DatabaseTestCase;

/**
 * The settings table: every bot has its own rows, a value comes back as it was kept — through a field, as the field
 * holds it —, a group is saved whole or not at all, a long-lived process's refresh() loses nothing, and the bot settings
 * screen — the modules' groups, together — has one field to a name.
 */
final class SettingsTest extends DatabaseTestCase
{
    public function testEveryBotHasItsOwnAndAnAgentsReadsTheDefaultsUntilItSetsOne(): void
    {
        $settings = $this->service(Settings::class);
        $agent = $this->agentBot();

        $settings->set('bot.support_contact', '@main_support');
        CurrentBot::run($agent, static fn() => $settings->set('bot.support_contact', '@agent_support'));
        $settings->set('bot.enabled', false, bot: $agent->id);

        $settings->refresh();
        self::assertSame('@main_support', $settings->get('bot.support_contact'));
        self::assertSame('@agent_support', CurrentBot::run($agent, static fn(): mixed => $settings->get('bot.support_contact')));
        self::assertSame(false, $settings->get('bot.enabled', true, $agent->id));
        self::assertSame(true, $settings->get('bot.enabled', true), "the main bot's is not the agent's");
        self::assertSame('"@agent_support"', self::row('bot.support_contact', $agent->id)->value, 'a row of its own');

        CurrentBot::run($agent, static fn() => $settings->forget('bot.support_contact'));
        self::assertNull(CurrentBot::run($agent, static fn(): mixed => $settings->get('bot.support_contact')), 'back to the default, which is none');
        self::assertSame('@main_support', $settings->get('bot.support_contact'));
    }

    public function testAValueComesBackAsItWasKept(): void
    {
        $settings = $this->service(Settings::class);
        $settings->set('bot.topup_presets', [50000, 100000]);
        $settings->set('bot.topup_min', 10000);
        $settings->set('bot.support_contact', '@امو "پشتیبانی"');

        $settings->refresh();

        self::assertSame([[50000, 100000], 10000, '@امو "پشتیبانی"'], [$settings->get('bot.topup_presets'), $settings->get('bot.topup_min'), $settings->get('bot.support_contact')]);
        self::assertSame('fallback', $settings->get('bot.nothing', 'fallback'), 'a key nobody wrote is the reader\'s default');
    }

    public function testAFieldReadsAKeptValueAsItsTypeAndOneItWouldRefuseAsItsDefault(): void
    {
        $settings = $this->service(Settings::class);
        $days = new Number('days', 'test.days', default: 3, label: 'تعداد روز', min: 1, max: 30);

        self::assertSame(3, $settings->read($days), 'nothing kept');
        $settings->set('test.days', '12');
        self::assertSame(12, $settings->read($days), 'kept as text, read as a number');
        $settings->set('test.days', 45);
        self::assertSame(3, $settings->read($days), 'kept under another rule');
        self::assertSame(['days' => 3], $settings->present(new Form('test', [$days])), 'and shown as read');
    }

    public function testAGroupIsSavedWholeOrNotAtAll(): void
    {
        $settings = $this->service(Settings::class);
        $group = new Form('pair', [new Toggle('first', 'test.first', default: false), new Toggle('second', 'test.second', default: false)]);
        // The database refuses the second write.
        $refusal = $this->whileListening(
            'eloquent.saving: ' . Setting::class,
            static function (Setting $row): void {
                if ($row->key === 'test.second') {
                    throw new \RuntimeException('disk full');
                }
            },
            static function () use ($settings, $group): ?string {
                try {
                    $settings->save($group, ['first' => true, 'second' => true]);
                } catch (\RuntimeException $e) {
                    return $e->getMessage();
                }

                return null;
            },
        );

        self::assertSame('disk full', $refusal, 'the save fails as the database did');
        self::assertNull($settings->get('test.first'), 'what this process holds is what the table holds');
        self::assertFalse(Setting::query()->where('key', 'test.first')->exists(), 'the first write went with the second');
    }

    public function testWritingAKeyAfterARefreshKeepsTheOtherKeysVisible(): void
    {
        $settings = $this->service(Settings::class);
        $settings->set('bot.keyboard.start', ['type' => 'inline']);

        // A long-lived process drops what it read, then writes its heartbeat before reading anything else.
        $settings->refresh();
        $settings->set('telegram.poll_heartbeat_at', '2026-09-18T15:00:00+00:00');

        self::assertSame(['type' => 'inline'], $settings->get('bot.keyboard.start'), 'the write must not become the whole cache');

        $settings->refresh();
        $settings->forget('telegram.poll_heartbeat_at');
        self::assertSame(['type' => 'inline'], $settings->get('bot.keyboard.start'));
    }

    public function testRefreshSeesWhatAnotherProcessWrote(): void
    {
        $settings = $this->service(Settings::class);
        self::assertNull($settings->get('bot.support_contact'));

        // The panel writes the row; the bot's process still holds what it read.
        Setting::query()->create(['bot_id' => Bot::MAIN, 'key' => 'bot.support_contact', 'value' => '"@support"']);
        self::assertNull($settings->get('bot.support_contact'), 'read once a process');

        $settings->refresh();
        self::assertSame('@support', $settings->get('bot.support_contact'));
    }

    public function testTheScreenSavesAGroupItHasAndHasOneFieldToAName(): void
    {
        $settings = $this->service(Settings::class);
        $module = static fn(string $group, string $key): DeclaresSettings => new class ($group, $key) implements DeclaresSettings {
            public function __construct(private readonly string $group, private readonly string $key) {}

            public function groups(): array
            {
                return [new Form($this->group, [new Toggle('enabled', $this->key, default: false)])];
            }
        };

        $screen = new BotSettingsScreen($settings, [$module('one', 'test.one')]);
        $screen->save('one', ['enabled' => true]);
        self::assertSame(['enabled' => true], $screen->present());

        try {
            $screen->save('two', ['enabled' => true]);
            self::fail('a group the screen does not have');
        } catch (UnknownGroupException $e) {
            self::assertSame(404, $e->status());
        }

        $this->expectException(\LogicException::class);
        (new BotSettingsScreen($settings, [$module('one', 'test.one'), $module('two', 'test.two')]))->present();
    }

    private static function row(string $key, int $bot = Bot::MAIN): Setting
    {
        return Setting::query()->where('bot_id', $bot)->where('key', $key)->firstOrFail();
    }
}
