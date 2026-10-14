<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Subscriptions\DTO\ClientNamePattern;
use App\Modules\Subscriptions\Enums\ClientNameMode;
use App\Modules\Subscriptions\Services\ClientNameSettings;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * «نام کاربری سرویس» in the bot settings (the `client_names` group, either panel): the variables a pattern may hold
 * come with the settings, each with a sample customer's value for the screen's example; a pattern is saved as typed,
 * blank for its default, and refused under its field for an unknown variable, a character a panel does not take, or
 * names that could repeat — neither {n} nor {random}, nor {seq} beside a variable that tells customers apart; the
 * buyers' two switches decide the ways offered.
 */
final class AdminClientNamesSettingsTest extends HttpTestCase
{
    private const PATH = '/api/admin/bot/settings/client_names';
    private const ALIKE = 'الگو باید {n} یا {random} را داشته باشد، یا {seq} را کنار {username}، {tg_id} یا {user_id}، تا نام کاربری سرویس‌ها تکراری نشود.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testTheSettingsComeWithTheVariablesAPatternMayHoldAndTheirExamples(): void
    {
        Carbon::setTestNow('2026-10-13 21:00:00');
        LocalTime::use('Asia/Tehran');
        try {
            $variables = $this->decode($this->get('/api/admin/bot/settings'))['name_variables'];
        } finally {
            LocalTime::use('UTC');
        }

        self::assertSame(['username', 'seq', 'tg_id', 'user_id', 'n', 'random', 'date'], array_column($variables, 'name'));
        self::assertSame(ClientNamePattern::VARIABLES['seq'], $variables[1]['description']);
        self::assertSame(['amir', '3', '123456789', '42', '128', 'k7m2qx', '261014'], array_column($variables, 'example'), "a sample customer's, the date the shop's today (half past midnight in Tehran)");
    }

    public function testThePatternsAndTheBuyersWaysAreSaved(): void
    {
        $response = $this->putJson(self::PATH, ['name_pattern' => 'vpn-{tg_id}-{n}', 'test_name_pattern' => 'trial_{random}', 'name_random' => true, 'name_custom' => false]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $settings = $this->service(ClientNameSettings::class);
        self::assertSame(['vpn-{tg_id}-{n}', 'trial_{random}', [ClientNameMode::Sequential, ClientNameMode::Random]], [$settings->pattern()->pattern, $settings->testPattern()->pattern, $settings->modes()]);

        $blank = $this->putJson(self::PATH, ['name_pattern' => '', 'test_name_pattern' => '  ', 'name_random' => false, 'name_custom' => true]);
        self::assertSame(['{username}_{seq}', 'test_{username}_{seq}'], [$this->decode($blank)['settings']['name_pattern'], $this->decode($blank)['settings']['test_name_pattern']], 'blank: the defaults');
        self::assertSame([ClientNameMode::Sequential, ClientNameMode::Custom], $settings->modes());
    }

    public function testAPatternIsRefusedUnderItsField(): void
    {
        $refusals = [
            'an unknown variable' => ['{username}_{seq}_{city}', 'متغیر {city} شناخته نشد'],
            'a character a panel does not take' => ['{username} {seq}', 'بیرون از متغیرها فقط حرف و رقم انگلیسی'],
            'nothing that tells names apart' => ['{username}_{tg_id}', self::ALIKE],
            "a customer's own count alone: every customer's first service named alike" => ['vpn_{seq}', self::ALIKE],
            'the day beside the count is no customer' => ['{date}-{seq}', self::ALIKE],
            'too long' => [str_repeat('a', 45) . '{n}x', 'الگو باید بین 1 تا 48 کاراکتر باشد'],
        ];
        foreach ($refusals as $why => [$pattern, $message]) {
            $response = $this->putJson(self::PATH, ['name_pattern' => '{username}_{seq}', 'test_name_pattern' => $pattern, 'name_random' => false, 'name_custom' => false]);

            self::assertSame(422, $response->getStatusCode(), $why);
            self::assertStringStartsWith($message, $this->decode($response)['errors']['test_name_pattern'][0] ?? '', $why);
        }

        $both = $this->decode($this->putJson(self::PATH, ['name_pattern' => '{user}', 'test_name_pattern' => 'a{seq}!', 'name_random' => false, 'name_custom' => false]));
        self::assertSame(['name_pattern', 'test_name_pattern'], array_keys($both['errors']), 'every refusal at once');
        self::assertSame(ClientNamePattern::DEFAULT, $this->service(ClientNameSettings::class)->pattern()->pattern, 'nothing stored from a refused save');

        foreach (['{tg_id}_{seq}', 'u{user_id}.{seq}', 'vip{n}', '{date}-{random}'] as $pattern) {
            self::assertSame(200, $this->putJson(self::PATH, ['name_pattern' => $pattern, 'test_name_pattern' => '', 'name_random' => false, 'name_custom' => false])->getStatusCode(), $pattern);
        }
    }
}
