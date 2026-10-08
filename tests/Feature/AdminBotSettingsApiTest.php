<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Settings\Exceptions\UnknownGroupException;
use App\Modules\Settings\Services\Settings;
use App\Modules\Subscriptions\Services\RenewalSettings;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Qr\QrBackground;
use App\Modules\Users\Services\WalletSettings;
use App\Support\Validation;
use Tests\HttpTestCase;

/**
 * The bot settings screen — every bot its own, in both panels: the modules' groups (the switches, the channel rule, the
 * wallet's top-up amounts, the QR code and its picture, renewal and its automatic kind, reminders, referrals, the report
 * group's topics — the agency's only in the main bot's shop), each saved on its own and refused whole, with each
 * group's bounds. (The field engine itself: Unit\Core\Forms.)
 */
final class AdminBotSettingsApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testAFreshShopHasTheBotOnNoRulesAndTheDefaultTopUpAmounts(): void
    {
        $response = $this->get('/api/admin/bot/settings');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'enabled' => true,
            'phone_required' => false,
            'support_contact' => '',
            'join_required' => false,
            'qr_enabled' => true,
            'topup_min' => 10000,
            'topup_presets' => [50000, 100000, 200000, 500000],
            'carry_traffic' => false,
            'auto_renew_days' => 2,
            'auto_renew_default' => false,
            'expiry_reminder' => false,
            'expiry_reminder_days' => 3,
            'traffic_reminder' => false,
            'traffic_reminder_percent' => 80,
            'referral_enabled' => false,
            'referral_rate' => 10,
            'referral_first_only' => false,
            'report_purchases' => true,
            'report_renewals' => true,
            'report_wallet' => true,
            'report_receipts' => true,
            'report_users' => true,
            'report_errors' => true,
            'report_agency' => true,
            'report_tickets' => true,
            'report_reviews' => true,
        ], $this->decode($response)['settings'], 'renewal: the traffic left not carried; automatic renewal two days before the deadline, off for new services; reminders and referrals off until the admin turns them on; every report topic wanted (no group until one is connected)');

        $background = $this->decode($response)['qr_background'];
        self::assertFalse($background['custom'], 'the shipped picture until the admin uploads one');
        self::assertSame(['image/jpeg', 1024, 1024], [$background['mime'], $background['width'], $background['height']]);
        self::assertSame(QrBackground::MAX_BYTES, $background['max_bytes'], 'the largest upload taken, for the card to say before it is refused');
    }

    public function testTheQrGroupIsOneSwitch(): void
    {
        $saved = $this->putJson('/api/admin/bot/settings/qr', ['qr_enabled' => false]);

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertFalse($this->decode($saved)['settings']['qr_enabled']);
        self::assertFalse($this->service(BotSettings::class)->qrEnabled());

        self::assertSame(422, $this->unchecked()->putJson('/api/admin/bot/settings/qr', ['qr_enabled' => 'maybe'])->getStatusCode());
    }

    public function testTheQrBackgroundCanBeUploadedServedAndResetToTheShippedOne(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true);

        // Not an image, whatever the name says.
        $refused = $this->upload('/api/admin/bot/qr-background', 'file', 'bg.png', 'hello, not a picture');
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('PNG', $this->decode($refused)['errors']['file'][0]);

        $uploaded = $this->upload('/api/admin/bot/qr-background', 'file', 'anything.jpg', $png);
        self::assertSame(200, $uploaded->getStatusCode(), (string) $uploaded->getBody());
        $background = $this->decode($uploaded)['qr_background'];
        self::assertTrue($background['custom']);
        self::assertSame(['image/png', 1, 1], [$background['mime'], $background['width'], $background['height']], 'typed by content: a png, not the .jpg it was named');
        self::assertSame('background.png', $this->service(Settings::class)->get(QrBackground::KEY));

        $served = $this->get('/api/admin/bot/qr-background');
        self::assertSame('image/png', $served->getHeaderLine('Content-Type'));
        self::assertSame($png, (string) $served->getBody(), 'the screen previews what the bot will draw on');

        $reset = $this->deleteJson('/api/admin/bot/qr-background');
        self::assertSame(200, $reset->getStatusCode());
        self::assertFalse($this->decode($reset)['qr_background']['custom']);
        self::assertNull($this->service(Settings::class)->get(QrBackground::KEY));
        self::assertSame(1024, $this->decode($this->get('/api/admin/bot/settings'))['qr_background']['width'], 'back to the shipped picture');
        self::assertFileDoesNotExist($this->app()->container()->get('qr.backgrounds') . '/background.png');
    }

    public function testAPictureTooLargeToDrawOnIsRefusedBeforeItIsDecoded(): void
    {
        // A PNG header saying 5000 x 5000 (25 megapixels) — the size is read from it, nothing is decoded.
        $ihdr = pack('NNCCCCC', 5000, 5000, 8, 2, 0, 0, 0);
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IEND', '');

        $refused = $this->upload('/api/admin/bot/qr-background', 'file', 'huge.png', $png);

        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('مگاپیکسل', $this->decode($refused)['errors']['file'][0]);
        self::assertNull($this->service(Settings::class)->get(QrBackground::KEY));
    }

    public function testALargePictureIsKeptAtTheSizeACardNeeds(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD is not installed.');
        }
        // A strip a little longer than a card needs: shrunk to MAX_SIDE, its proportions kept.
        $image = imagecreatetruecolor(QrBackground::MAX_SIDE + 2, 2);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $uploaded = $this->upload('/api/admin/bot/qr-background', 'file', 'wide.png', $png);

        self::assertSame(200, $uploaded->getStatusCode(), (string) $uploaded->getBody());
        $background = $this->decode($uploaded)['qr_background'];
        self::assertSame(['image/png', QrBackground::MAX_SIDE, 2], [$background['mime'], $background['width'], $background['height']], '2 × 2048 / 2050, to the nearest');
    }

    public function testTheWalletGroupTakesAMinimumAndPresetsInAnyReasonableForm(): void
    {
        $response = $this->putJson('/api/admin/bot/settings/wallet', ['topup_min' => '۲۰٬۰۰۰', 'topup_presets' => '100000، 20000 , 50000, 50000']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $settings = $this->decode($response)['settings'];
        self::assertSame(20000, $settings['topup_min']);
        self::assertSame([20000, 50000, 100000], $settings['topup_presets'], 'sorted, deduplicated, Persian digits and commas accepted');
        self::assertTrue($settings['enabled'], 'the other group is untouched');

        // Thousands set apart, as the screen's hint reads them: a comma between groups of three splits no amount.
        $grouped = $this->decode($this->putJson('/api/admin/bot/settings/wallet', ['topup_min' => '10,000', 'topup_presets' => '50,000, 100,000']))['settings'];
        self::assertSame([10000, [50000, 100000]], [$grouped['topup_min'], $grouped['topup_presets']]);

        $asList = $this->putJson('/api/admin/bot/settings/wallet', ['topup_min' => 20000, 'topup_presets' => [30000, '40000']]);
        self::assertSame([30000, 40000], $this->decode($asList)['settings']['topup_presets']);

        $bad = $this->putJson('/api/admin/bot/settings/wallet', ['topup_min' => '500', 'topup_presets' => 'abc']);
        self::assertSame(422, $bad->getStatusCode());
        $errors = $this->decode($bad)['errors'];
        self::assertArrayHasKey('topup_min', $errors);
        self::assertArrayHasKey('topup_presets', $errors);

        $below = $this->putJson('/api/admin/bot/settings/wallet', ['topup_min' => 50000, 'topup_presets' => '20000']);
        self::assertSame(422, $below->getStatusCode());
        self::assertSame(['هیچ‌کدام از مبلغ‌های پیشنهادی نمی‌تواند از حداقل شارژ کمتر باشد.'], $this->decode($below)['errors']['topup_presets']);

        $many = $this->putJson('/api/admin/bot/settings/wallet', ['topup_min' => 10000, 'topup_presets' => implode(' ', range(10000, 90000, 10000))]);
        self::assertSame(['مبلغ‌های پیشنهادی حداکثر 8 عدد است.'], $this->decode($many)['errors']['topup_presets']);
        self::assertSame([30000, 40000], $this->service(WalletSettings::class)->topUpPresets(), 'nothing stored from a refused save');

        foreach (['nope', 'agency'] as $group) {
            $unknown = $this->unchecked()->putJson("/api/admin/bot/settings/{$group}", []);
            self::assertSame([404, (new UnknownGroupException())->getMessage()], [$unknown->getStatusCode(), $this->decode($unknown)['message']], "{$group}: no group of the screen — the agency's rules are the shop's, on the agents page");
        }
    }

    public function testTheRenewalGroupDecidesWhatBecomesOfTheTrafficLeft(): void
    {
        $response = $this->putJson('/api/admin/bot/settings/renewal', ['carry_traffic' => true]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($this->decode($response)['settings']['carry_traffic']);
        self::assertTrue($this->service(RenewalSettings::class)->carriesTraffic());

        $bad = $this->unchecked()->putJson('/api/admin/bot/settings/renewal', ['carry_traffic' => 'maybe']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertSame([Validation::NOT_A_SWITCH], $this->decode($bad)['errors']['carry_traffic']);
        self::assertTrue($this->service(RenewalSettings::class)->carriesTraffic(), 'nothing stored from a refused save');
    }

    public function testTheAutoRenewGroupTakesTheDaysAndTheDefaultForNewServices(): void
    {
        $response = $this->putJson('/api/admin/bot/settings/auto_renew', ['auto_renew_days' => '۵', 'auto_renew_default' => true]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $settings = $this->decode($response)['settings'];
        self::assertSame([5, true], [$settings['auto_renew_days'], $settings['auto_renew_default']], 'Persian digits accepted');
        self::assertFalse($settings['carry_traffic'], 'the other renewal card is untouched');

        $bad = $this->unchecked()->putJson('/api/admin/bot/settings/auto_renew', ['auto_renew_days' => 0, 'auto_renew_default' => 'maybe']);
        self::assertSame(422, $bad->getStatusCode());
        $errors = $this->decode($bad)['errors'];
        self::assertSame(['تعداد روز باید عددی بین 1 تا 30 باشد.'], $errors['auto_renew_days']);
        self::assertSame([Validation::NOT_A_SWITCH], $errors['auto_renew_default']);
        self::assertSame(5, $this->decode($this->get('/api/admin/bot/settings'))['settings']['auto_renew_days'], 'nothing stored from a refused save');
    }

    public function testTheRemindersGroupTakesBothRemindersWithTheirThresholds(): void
    {
        $response = $this->putJson('/api/admin/bot/settings/reminders', [
            'expiry_reminder' => true,
            'expiry_reminder_days' => '۵',
            'traffic_reminder' => true,
            'traffic_reminder_percent' => 90,
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $settings = $this->decode($response)['settings'];
        self::assertSame([true, 5, true, 90], [$settings['expiry_reminder'], $settings['expiry_reminder_days'], $settings['traffic_reminder'], $settings['traffic_reminder_percent']]);

        $bad = $this->unchecked()->putJson('/api/admin/bot/settings/reminders', ['expiry_reminder' => false, 'expiry_reminder_days' => 31, 'traffic_reminder' => 'maybe', 'traffic_reminder_percent' => 100]);
        self::assertSame(422, $bad->getStatusCode());
        $errors = $this->decode($bad)['errors'];
        self::assertSame(['تعداد روز باید عددی بین 1 تا 30 باشد.'], $errors['expiry_reminder_days']);
        self::assertSame(['درصد باید عددی بین 50 تا 99 باشد.'], $errors['traffic_reminder_percent']);
        self::assertSame([Validation::NOT_A_SWITCH], $errors['traffic_reminder']);
        self::assertTrue($this->decode($this->get('/api/admin/bot/settings'))['settings']['expiry_reminder'], 'nothing stored from a refused save');
    }

    public function testTheReferralGroupTakesTheSwitchTheRateAndTheFirstPaymentRule(): void
    {
        $response = $this->putJson('/api/admin/bot/settings/referral', ['referral_enabled' => true, 'referral_rate' => '۱۵', 'referral_first_only' => true]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $settings = $this->decode($response)['settings'];
        self::assertSame([true, 15, true], [$settings['referral_enabled'], $settings['referral_rate'], $settings['referral_first_only']]);

        $bad = $this->unchecked()->putJson('/api/admin/bot/settings/referral', ['referral_enabled' => false, 'referral_rate' => 0, 'referral_first_only' => 'sometimes']);
        self::assertSame(422, $bad->getStatusCode());
        $errors = $this->decode($bad)['errors'];
        self::assertSame(['درصد پورسانت باید عددی بین 1 تا 100 باشد.'], $errors['referral_rate']);
        self::assertSame([Validation::NOT_A_SWITCH], $errors['referral_first_only']);
        self::assertTrue($this->decode($this->get('/api/admin/bot/settings'))['settings']['referral_enabled'], 'nothing stored from a refused save');
    }

    public function testAnAgentsBotHasSettingsOfItsOwn(): void
    {
        $this->loginAsAgent($this->agentBot());

        $saved = $this->putJson('/api/agent/bot/settings/general', ['enabled' => true, 'phone_required' => true, 'support_contact' => '@agent_support']);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $settings = $this->decode($this->get('/api/agent/bot/settings'))['settings'];
        self::assertSame('@agent_support', $settings['support_contact']);
        self::assertSame(404, $this->unchecked()->putJson('/api/agent/bot/settings/agency', ['enabled' => false])->getStatusCode(), "the agency's rules are no bot's settings");

        // Agency requests and traffic purchases are the main bot's group's to hear about: an agent's bot has no such switch.
        // Its tickets are its own to hear about.
        self::assertArrayNotHasKey('report_agency', $settings);
        self::assertTrue($settings['report_tickets']);
        $reports = ['report_purchases' => true, 'report_renewals' => false, 'report_wallet' => true, 'report_receipts' => true, 'report_users' => true, 'report_errors' => true, 'report_tickets' => true, 'report_reviews' => true];
        self::assertSame(200, $this->putJson('/api/agent/bot/settings/reports', $reports)->getStatusCode());
        self::assertFalse($this->decode($this->get('/api/agent/bot/settings'))['settings']['report_renewals']);

        // The main bot's are its own still.
        $main = $this->decode($this->get('/api/admin/bot/settings'))['settings'];
        self::assertSame(['', false, true, true], [$main['support_contact'], $main['phone_required'], $main['report_renewals'], $main['report_agency']]);
    }

    public function testSavingTheSwitchesReachesTheBot(): void
    {
        $response = $this->putJson('/api/admin/bot/settings/general', ['enabled' => false, 'phone_required' => true, 'support_contact' => ' @amo_support ']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            ['enabled' => false, 'phone_required' => true, 'support_contact' => '@amo_support', 'join_required' => false],
            array_intersect_key($this->decode($response)['settings'], array_flip(['enabled', 'phone_required', 'join_required', 'support_contact'])),
            'the channel rule is its own group, untouched',
        );

        $channels = $this->putJson('/api/admin/bot/settings/channels', ['join_required' => true]);
        self::assertSame(200, $channels->getStatusCode(), (string) $channels->getBody());
        self::assertTrue($this->decode($channels)['settings']['join_required']);

        $settings = $this->service(BotSettings::class);
        self::assertFalse($settings->enabled());
        self::assertTrue($settings->phoneRequired());
        self::assertTrue($settings->joinRequired());
        self::assertSame('@amo_support', $settings->supportContact(), 'what the bot shows on its support screen');

        $this->putJson('/api/admin/bot/settings/general', ['enabled' => true, 'phone_required' => false]);
        $this->putJson('/api/admin/bot/settings/channels', ['join_required' => false]);
        self::assertSame(['enabled' => true, 'phone_required' => false, 'join_required' => false], array_intersect_key($this->decode($this->get('/api/admin/bot/settings'))['settings'], array_flip(['enabled', 'phone_required', 'join_required'])));
    }

    public function testEverySwitchMustBeSentAsABoolean(): void
    {
        $response = $this->unchecked()->putJson('/api/admin/bot/settings/general', ['enabled' => 'maybe']);

        self::assertSame(422, $response->getStatusCode());
        $errors = $this->decode($response)['errors'];
        self::assertArrayHasKey('enabled', $errors, 'a value that is neither on nor off');
        self::assertArrayHasKey('phone_required', $errors, 'a missing switch is not silently "off"');
        self::assertTrue($this->service(BotSettings::class)->enabled(), 'nothing was written');

        $channels = $this->unchecked()->putJson('/api/admin/bot/settings/channels', []);
        self::assertSame([Validation::NOT_A_SWITCH], $this->decode($channels)['errors']['join_required']);
    }
}
