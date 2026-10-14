<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Rewards\Forms\PrizePlan;
use App\Modules\Settings\Exceptions\UnknownGroupException;
use App\Modules\Settings\Services\Settings;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Services\ExpiredServicesSettings;
use App\Modules\Subscriptions\Services\ExtrasSettings;
use App\Modules\Subscriptions\Services\RenewalSettings;
use App\Modules\Subscriptions\Services\ServiceClaims;
use App\Modules\Subscriptions\Services\ServiceClaimSettings;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Qr\QrBackground;
use App\Modules\Users\Services\WalletSettings;
use App\Support\Validation;
use Tests\HttpTestCase;

/**
 * The bot settings screen — every bot its own, in both panels: the modules' groups (the switches, the channel rule, the
 * wallet's top-up amounts, the QR code and its picture, renewal and its automatic kind, extra traffic and time and their
 * prices, reminders, the removal of ended services, services added by their link, referrals, the report group's topics
 * — the agency's only in the main bot's shop), each saved on its own and refused whole, with each group's bounds. (The
 * field engine itself: Unit\Core\Forms.)
 */
final class AdminBotSettingsApiTest extends HttpTestCase
{
    /** The renewal group's rules besides `carry_traffic`, as a fresh shop has them. */
    private const RENEWAL_RULES = ['renew_window_days' => 0, 'renew_window_percent' => 0, 'renew_extend' => true, 'renew_reset_traffic' => false, 'renew_reset_time' => false, 'renew_default_method' => 'extend'];

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
            'renew_window_days' => 0,
            'renew_window_percent' => 0,
            'renew_extend' => true,
            'renew_reset_traffic' => false,
            'renew_reset_time' => false,
            'renew_default_method' => 'extend',
            'auto_renew_days' => 2,
            'auto_renew_default' => false,
            'extras_enabled' => false,
            'extra_traffic_price' => null,
            'extra_traffic_options' => [],
            'extra_traffic_min' => 1,
            'extra_traffic_max' => 100,
            'extra_time_price' => null,
            'extra_time_options' => [],
            'extra_time_min' => 1,
            'extra_time_max' => 30,
            'extra_devices_price' => null,
            'extra_devices_options' => [],
            'extra_devices_min' => 1,
            'extra_devices_max' => 5,
            'expiry_reminder' => false,
            'expiry_reminder_days' => 3,
            'traffic_reminder' => false,
            'traffic_reminder_percent' => 80,
            'expired_cleanup' => false,
            'expired_cleanup_hours' => 24,
            'test_enabled' => false,
            'test_per_user' => 1,
            'test_period_days' => 0,
            'test_daily_limit' => 0,
            'test_newcomers_only' => false,
            'test_cleanup' => true,
            'test_cleanup_hours' => 24,
            'test_unused_days' => 7,
            'claim_enabled' => false,
            'transfer_enabled' => false,
            'name_pattern' => '{username}_{seq}',
            'test_name_pattern' => 'test_{username}_{seq}',
            'name_random' => false,
            'name_custom' => false,
            'custom_enabled' => false,
            'custom_traffic_tiers' => [],
            'custom_traffic_min' => 1,
            'custom_traffic_max' => 500,
            'custom_traffic_options' => [10, 20, 50, 100],
            'custom_days_tiers' => [],
            'custom_days_min' => 1,
            'custom_days_max' => 90,
            'custom_days_options' => [30, 60, 90],
            'custom_devices' => 2,
            'custom_servers' => [],
            'referral_enabled' => false,
            'referral_rate' => 10,
            'referral_renewal_rate' => 10,
            'referral_first_only' => false,
            'referral_start_referrer' => '0.00',
            'referral_start_invitee' => '0.00',
            'referral_start_when' => 'purchase',
            'referral_reward_every' => 0,
            'referral_reward_plan_id' => null,
            'referral_alert_burst' => 10,
            'referral_alert_minutes' => 60,
            'referral_alert_idle' => 20,
            'report_purchases' => true,
            'report_renewals' => true,
            'report_wallet' => true,
            'report_receipts' => true,
            'report_users' => true,
            'report_errors' => true,
            'report_agency' => true,
            'report_tickets' => true,
            'report_reviews' => true,
            'hold_minutes' => 60,
            'gift_codes_enabled' => false,
            'wheel_enabled' => false,
            'wheel_cooldown_hours' => 24,
            'wheel_buyers_only' => false,
            'wheel_cost' => 0,
            'cashback_enabled' => false,
            'lottery_enabled' => false,
            'lottery_hour' => 22,
            'lottery_winners' => 1,
            'lottery_prize' => 'wallet',
            'lottery_wallet' => '50000.00',
            'lottery_traffic_gb' => 10,
            'lottery_days' => 7,
            'lottery_plan_id' => null,
            'lottery_entries' => 'order',
            'lottery_exclude_agents' => true,
        ], $this->decode($response)['settings'], 'renewal: the traffic left not carried; automatic renewal two days before the deadline, off for new services; extra traffic and time, reminders, the removal of ended services, test accounts, services added by their link and referrals off until the admin turns them on (a day after its end; a test each, for good; ended tests cleaned up); every report topic wanted (no group until one is connected)');

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
        $response = $this->putJson('/api/admin/bot/settings/renewal', ['carry_traffic' => true] + self::RENEWAL_RULES);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($this->decode($response)['settings']['carry_traffic']);
        self::assertTrue($this->service(RenewalSettings::class)->carriesTraffic());

        $bad = $this->unchecked()->putJson('/api/admin/bot/settings/renewal', ['carry_traffic' => 'maybe'] + self::RENEWAL_RULES);
        self::assertSame(422, $bad->getStatusCode());
        self::assertSame([Validation::NOT_A_SWITCH], $this->decode($bad)['errors']['carry_traffic']);
        self::assertTrue($this->service(RenewalSettings::class)->carriesTraffic(), 'nothing stored from a refused save');
    }

    public function testTheRenewalGroupTakesTheWindowAndTheMethodsOneAtLeastOnAndTheDefaultAmongThem(): void
    {
        $rules = ['carry_traffic' => false, 'renew_window_days' => '۵', 'renew_window_percent' => 20, 'renew_extend' => false, 'renew_reset_traffic' => true, 'renew_reset_time' => true, 'renew_default_method' => 'reset_time'];

        $response = $this->putJson('/api/admin/bot/settings/renewal', $rules);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $settings = $this->decode($response)['settings'];
        self::assertSame([5, 20, false, true, true, 'reset_time'], [$settings['renew_window_days'], $settings['renew_window_percent'], $settings['renew_extend'], $settings['renew_reset_traffic'], $settings['renew_reset_time'], $settings['renew_default_method']], 'Persian digits accepted');
        $renewal = $this->service(RenewalSettings::class);
        self::assertSame([5, 20, [RenewalMethod::ResetTraffic, RenewalMethod::ResetTime], RenewalMethod::ResetTime], [$renewal->windowDays(), $renewal->windowPercent(), $renewal->methods(), $renewal->defaultMethod()]);

        $none = $this->putJson('/api/admin/bot/settings/renewal', ['renew_reset_traffic' => false, 'renew_reset_time' => false, 'renew_default_method' => 'extend'] + $rules);
        self::assertSame([422, ['renew_extend' => [RenewalSettings::NO_METHOD]]], [$none->getStatusCode(), $this->decode($none)['errors']], 'no method on: said once, under the first');

        $off = $this->putJson('/api/admin/bot/settings/renewal', ['renew_default_method' => 'extend'] + $rules);
        self::assertSame([422, ['renew_default_method' => [RenewalSettings::DEFAULT_OFF]]], [$off->getStatusCode(), $this->decode($off)['errors']], 'a default that is off');

        $bounds = $this->putJson('/api/admin/bot/settings/renewal', ['renew_window_days' => 366, 'renew_window_percent' => 101] + $rules);
        self::assertSame(422, $bounds->getStatusCode());
        self::assertSame(['بازه تمدید باید عددی بین 0 تا 365 روز باشد.'], $this->decode($bounds)['errors']['renew_window_days']);
        self::assertSame(['حجم باقی‌مانده برای تمدید باید عددی بین 0 تا 100 درصد باشد.'], $this->decode($bounds)['errors']['renew_window_percent']);

        self::assertSame([[RenewalMethod::ResetTraffic, RenewalMethod::ResetTime], 5], [$renewal->methods(), $renewal->windowDays()], 'nothing stored from a refused save');
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

    public function testTheExpiredServicesGroupTakesTheSwitchAndTheHoursAfterTheEnd(): void
    {
        $response = $this->putJson('/api/admin/bot/settings/expired_services', ['expired_cleanup' => true, 'expired_cleanup_hours' => '۱۲']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $settings = $this->decode($response)['settings'];
        self::assertSame([true, 12], [$settings['expired_cleanup'], $settings['expired_cleanup_hours']], 'Persian digits accepted');
        self::assertTrue($settings['test_cleanup'], "the tests' own rule is untouched");
        $expired = $this->service(ExpiredServicesSettings::class);
        self::assertSame([true, 12], [$expired->enabled(), $expired->hours()]);

        $bad = $this->unchecked()->putJson('/api/admin/bot/settings/expired_services', ['expired_cleanup_hours' => ExpiredServicesSettings::HOURS_MAX + 1]);
        self::assertSame(422, $bad->getStatusCode());
        self::assertSame([
            'expired_cleanup' => [Validation::NOT_A_SWITCH],
            'expired_cleanup_hours' => ['فاصله حذف باید عددی بین 0 تا 8760 ساعت باشد.'],
        ], $this->decode($bad)['errors'], 'a missing switch is not "off"; more than a year is refused');
        self::assertSame(12, $this->decode($this->get('/api/admin/bot/settings'))['settings']['expired_cleanup_hours'], 'nothing stored from a refused save');

        $atOnce = $this->putJson('/api/admin/bot/settings/expired_services', ['expired_cleanup' => true, 'expired_cleanup_hours' => 0]);
        self::assertSame(0, $this->decode($atOnce)['settings']['expired_cleanup_hours'], '0: removed as soon as it ends');
    }

    public function testTheServiceClaimsGroupIsOneSwitchTheShopTakesLinksBy(): void
    {
        $claims = $this->service(ServiceClaims::class);
        self::assertFalse($claims->offered(), 'off until the admin turns it on');

        $response = $this->putJson('/api/admin/bot/settings/service_claims', ['claim_enabled' => true]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($this->decode($response)['settings']['claim_enabled']);
        self::assertTrue($this->service(ServiceClaimSettings::class)->enabled());
        self::assertTrue($claims->offered(), 'the main shop now takes services by their link');

        $bad = $this->unchecked()->putJson('/api/admin/bot/settings/service_claims', ['claim_enabled' => 'maybe']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertSame(['claim_enabled' => [Validation::NOT_A_SWITCH]], $this->decode($bad)['errors']);
        $missing = $this->unchecked()->putJson('/api/admin/bot/settings/service_claims', []);
        self::assertSame(['claim_enabled' => [Validation::NOT_A_SWITCH]], $this->decode($missing)['errors'], 'a missing switch is not "off"');
        self::assertTrue($this->decode($this->get('/api/admin/bot/settings'))['settings']['claim_enabled'], 'nothing stored from a refused save');

        $off = $this->putJson('/api/admin/bot/settings/service_claims', ['claim_enabled' => false]);
        self::assertFalse($this->decode($off)['settings']['claim_enabled']);
        self::assertFalse($claims->offered());
    }

    public function testAnAgentsShopHasNoServiceClaimsGroupInEitherPanel(): void
    {
        $this->putJson('/api/admin/bot/settings/service_claims', ['claim_enabled' => true]);
        $bot = $this->agentBot();
        $unknown = [404, (new UnknownGroupException())->getMessage()];

        $this->loginAsAgent($bot);
        self::assertArrayNotHasKey('claim_enabled', $this->decode($this->get('/api/agent/bot/settings'))['settings'], "the agent's panel: no shop but the main one takes services by their link");
        $agent = $this->putJson('/api/agent/bot/settings/service_claims', ['claim_enabled' => true]);
        self::assertSame($unknown, [$agent->getStatusCode(), $this->decode($agent)['message']]);

        $this->openShop($bot);
        self::assertArrayNotHasKey('claim_enabled', $this->decode($this->get('/api/admin/bot/settings'))['settings'], "the owner with the agent's shop open");
        $owner = $this->putJson('/api/admin/bot/settings/service_claims', ['claim_enabled' => false]);
        self::assertSame($unknown, [$owner->getStatusCode(), $this->decode($owner)['message']]);

        $this->openShop(CurrentBot::main());
        self::assertTrue($this->decode($this->get('/api/admin/bot/settings'))['settings']['claim_enabled'], "the main shop's switch untouched");
        self::assertTrue($this->service(ServiceClaims::class)->offered());
    }

    public function testTheReferralGroupTakesItsCommissionsGiftsRewardAndAlerts(): void
    {
        $plan = $this->plan(['name' => 'جایزه']);
        $group = [
            'referral_enabled' => true,
            'referral_rate' => '۱۵',
            'referral_renewal_rate' => '0',
            'referral_first_only' => true,
            'referral_start_referrer' => '۲۰٬۰۰۰',
            'referral_start_invitee' => 10000,
            'referral_start_when' => 'join',
            'referral_reward_every' => '5',
            'referral_reward_plan_id' => (string) $plan->id,
            'referral_alert_burst' => 0,
            'referral_alert_minutes' => '30',
            'referral_alert_idle' => 40,
        ];
        $response = $this->putJson('/api/admin/bot/settings/referral', $group);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $settings = $this->decode($response)['settings'];
        self::assertSame(
            [true, 15, 0, true, '20000.00', '10000.00', 'join', 5, $plan->id, 0, 30, 40],
            array_map(static fn(string $name): mixed => $settings[$name], array_keys($group)),
            'as the admin typed them, Persian digits and separators too',
        );

        $otherShops = CurrentBot::run($this->agentBot(), fn(): Plan => $this->plan(['name' => 'نماینده']));
        $bad = $this->unchecked()->putJson('/api/admin/bot/settings/referral', [
            'referral_rate' => 0,
            'referral_renewal_rate' => 101,
            'referral_first_only' => 'sometimes',
            'referral_start_referrer' => '-1',
            'referral_start_when' => 'tomorrow',
            'referral_reward_plan_id' => $otherShops->id,
            'referral_alert_minutes' => 0,
        ] + $group);
        self::assertSame(422, $bad->getStatusCode());
        $errors = $this->decode($bad)['errors'];
        self::assertSame(['درصد پورسانت باید عددی بین 1 تا 100 باشد.'], $errors['referral_rate']);
        self::assertSame(['درصد پورسانت تمدید باید عددی بین 0 تا 100 باشد.'], $errors['referral_renewal_rate']);
        self::assertSame([Validation::NOT_A_SWITCH], $errors['referral_first_only']);
        self::assertSame(['هدیه شروع معرف باید مبلغی بین ۰ تومان تا ۱۰٬۰۰۰٬۰۰۰ تومان باشد.'], $errors['referral_start_referrer']);
        self::assertArrayHasKey('referral_start_when', $errors);
        self::assertSame([PrizePlan::NOT_A_PLAN], $errors['referral_reward_plan_id'], "another shop's plan is none of this one's");
        self::assertSame(['بازه هشدار باید عددی بین 1 تا 10080 دقیقه باشد.'], $errors['referral_alert_minutes']);

        $none = $this->putJson('/api/admin/bot/settings/referral', ['referral_reward_plan_id' => null] + $group);
        self::assertSame([PrizePlan::PICK], $this->decode($none)['errors']['referral_reward_plan_id'], 'a reward needs its plan');
        self::assertSame(15, $this->decode($this->get('/api/admin/bot/settings'))['settings']['referral_rate'], 'nothing stored from a refused save');

        $off = $this->putJson('/api/admin/bot/settings/referral', ['referral_reward_every' => 0, 'referral_reward_plan_id' => ''] + $group);
        self::assertNull($this->decode($off)['settings']['referral_reward_plan_id'], 'no reward, no plan');
    }

    public function testTheExtrasGroupTakesTheSwitchAndTheShopsPriceListAsTheAdminTypesIt(): void
    {
        $response = $this->putJson('/api/admin/bot/settings/extras', [
            'extras_enabled' => true,
            'extra_traffic_price' => '۲٬۰۰۰',
            'extra_traffic_options' => [['quantity' => '50', 'price' => null], ['quantity' => 10, 'price' => '18,000'], ['quantity' => null, 'price' => '']],
            'extra_traffic_min' => '۵',
            'extra_traffic_max' => 200,
            'extra_time_price' => null,
            'extra_time_options' => [['quantity' => 30, 'price' => 25000]],
            'extra_time_min' => 1,
            'extra_time_max' => 30,
            'extra_devices_price' => '30000',
            'extra_devices_options' => [['quantity' => '۲', 'price' => '50000']],
            'extra_devices_min' => 1,
            'extra_devices_max' => '۳',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $saved = array_intersect_key($this->decode($response)['settings'], array_flip(['extras_enabled', 'extra_traffic_price', 'extra_traffic_options', 'extra_traffic_min', 'extra_traffic_max', 'extra_time_price', 'extra_time_options', 'extra_devices_price', 'extra_devices_options', 'extra_devices_max']));
        self::assertSame([
            'extras_enabled' => true,
            'extra_traffic_price' => '2000.00',
            'extra_traffic_options' => [['quantity' => 10, 'price' => '18000.00'], ['quantity' => 50, 'price' => null]],
            'extra_traffic_min' => 5,
            'extra_traffic_max' => 200,
            'extra_time_price' => null,
            'extra_time_options' => [['quantity' => 30, 'price' => '25000.00']],
            'extra_devices_price' => '30000.00',
            'extra_devices_options' => [['quantity' => 2, 'price' => '50000.00']],
            'extra_devices_max' => 3,
        ], $saved, 'Persian digits and separators, a blank row dropped, the smallest first');
        $prices = $this->service(ExtrasSettings::class)->prices();
        self::assertSame(['100000.00', null, '90000.00'], [$prices->traffic->priceOf(50), $prices->time->priceOf(7), $prices->devices->priceOf(3)], 'fifty GB at the price of a GB; no price of a day, only the thirty days; three devices at 30,000 each for 30 days');

        $bad = $this->putJson('/api/admin/bot/settings/extras', ['extras_enabled' => true, 'extra_traffic_price' => '', 'extra_traffic_options' => [['quantity' => 10, 'price' => '']], 'extra_traffic_min' => 50, 'extra_traffic_max' => 20, 'extra_time_min' => 1, 'extra_time_max' => 30, 'extra_devices_min' => 1, 'extra_devices_max' => 21]);
        self::assertSame(422, $bad->getStatusCode());
        self::assertSame([
            'extra_devices_max' => ['بیشترین تعداد دلخواه باید عددی بین 1 تا 20 کاربر باشد.'],
            'extra_traffic_options' => ['۱۰ گیگابایت قیمت ندارد؛ قیمت آن، یا قیمت هر گیگابایت را بنویسید.'],
            'extra_traffic_max' => ['بیشترین حجم دلخواه نمی‌تواند از کمترین حجم دلخواه کمتر باشد.'],
        ], $this->decode($bad)['errors']);
        self::assertSame('2000.00', $this->service(ExtrasSettings::class)->prices()->traffic->price, 'nothing stored from a refused save');
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
