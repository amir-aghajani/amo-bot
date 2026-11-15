<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Bots\CurrentBot;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Qr\QrBackground;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use GuzzleHttp\Psr7\Response;
use Tests\BotTestCase;

/**
 * «🤖 تنظیمات ربات» from the bot (Sections\Bot\Settings): every card of the panel's page on its menu, a switch flipped at a
 * tap and a value typed, each saved through the panel's own route and refused by its rules; the cards' own ways — the
 * amounts with prices, a method switched off taking automatic renewal's with it, a plan picked, the QR picture sent —;
 * and none of it for a shop's admin.
 */
final class BotAreaSettingsTest extends BotTestCase
{
    private const OWNER = 7001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheMenuHasEveryCardAndASwitchSavesAtATap(): void
    {
        $this->owner();

        $this->send($this->tap(Nav::to('hc'), chat: self::OWNER));
        self::assertContains(Nav::to('bs'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('bs'), chat: self::OWNER));
        foreach (['bsg', 'bsn', 'ch', 'bsw', 'bst', 'bsp', 'bsr', 'bsx', 'bsm', 'bse', 'bsc', 'bsv', 'bsf', 'rg', 'bsq', 'ba'] as $card) {
            self::assertContains(Nav::to($card, 'show', 'from.bs'), $this->callbacks(0), $card);
        }

        $this->send($this->tap(Nav::to('bsg', 'show', 'from.bs'), chat: self::OWNER));
        self::assertStringContainsString('ربات فعال باشد', $this->said()[0]);
        self::assertContains(Nav::to('bsg', 'e', 0, 0), $this->callbacks(0), 'the switch\'s button carries the value it sets');

        // Off holds every customer back: asked first; a second «yes» (a double tap) changes nothing more.
        $this->send($this->tap(Nav::to('bsg', 'e', 0, 0), chat: self::OWNER));
        self::assertTrue($this->service(BotSettings::class)->enabled(), 'asked, not done');
        self::assertContains(Nav::to('bsg', 'e', 0, 0, 'y'), $this->callbacks(0));
        self::assertContains(Nav::to('bsg', 'v'), $this->callbacks(0), '«✖️ انصراف» back to the card');
        $this->send($this->tap(Nav::to('bsg', 'e', 0, 0, 'y'), chat: self::OWNER));
        self::assertFalse($this->service(BotSettings::class)->enabled(), 'saved through the panel\'s route');
        self::assertStringContainsString('ذخیره شد', (string) $this->popup());
        $this->send($this->tap(Nav::to('bsg', 'e', 0, 0, 'y'), chat: self::OWNER));
        self::assertFalse($this->service(BotSettings::class)->enabled());
        self::assertStringContainsString('همین حالا خاموش است', (string) $this->popup(), 'a stale button sets what it said, never the opposite');

        $this->send($this->tap(Nav::to('bs'), chat: self::OWNER));
        self::assertStringContainsString('ربات خاموش است', $this->said()[0], 'said over the menu, as over the panel\'s page');
        self::assertStringContainsString('مالک، نماینده و مدیران ربات', $this->said()[0]);

        $this->send($this->tap(Nav::to('bsg', 'e', 0, 1), chat: self::OWNER));
        self::assertTrue($this->service(BotSettings::class)->enabled(), 'on again at one tap');

        $this->send($this->tap(Nav::to('bsg', 'e', 1, 1), chat: self::OWNER));
        self::assertFalse($this->settings()['phone_required'], 'the phone rule on is asked first');
        $this->send($this->tap(Nav::to('bsg', 'e', 1, 1, 'y'), chat: self::OWNER));
        self::assertTrue($this->settings()['phone_required']);

        $this->send($this->tap(Nav::to('bsg', 'help'), chat: self::OWNER));
        self::assertStringContainsString('برای تعطیلی موقت', $this->said()[0], 'a switch\'s hint, one tap away');
    }

    public function testACardOpenedFromTheMenuGoesBackThereAndFromItsOwnPlaceToThat(): void
    {
        $this->owner();

        // A program's card: back to the menu it was opened from, across its own actions — and to its list otherwise.
        $this->send($this->tap(Nav::to('dcs', 'show', 'from.bs'), chat: self::OWNER));
        self::assertContains(Nav::to('bs'), $this->callbacks(0));
        self::assertContains(Nav::to('dcs', 'help'), $this->callbacks(0), 'every card has its hints one tap away');
        $this->send($this->tap(Nav::to('dcs', 'e', 1, 1), chat: self::OWNER));
        self::assertContains(Nav::to('bs'), $this->buttons(), 'still, after a switch');
        $this->send($this->tap(Nav::to('dcs', 'e', 0), chat: self::OWNER));
        self::assertContains(Nav::to('dcs', 'v'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('dcs', 'v'), chat: self::OWNER));
        self::assertContains(Nav::to('bs'), $this->callbacks(0), 'still, after a question left');
        $this->send($this->tap(Nav::to('dcs'), chat: self::OWNER));
        self::assertNotContains(Nav::to('bs'), $this->callbacks(0));
        self::assertContains(Nav::to('dc'), $this->callbacks(0), 'opened from its own list: back there');

        // The channels and the report group: back to the menu, through their own screens.
        $channel = $this->channel(-1001000000009, 'اخبار');
        $this->send($this->tap(Nav::to('ch', 'show', 'from.bs'), chat: self::OWNER));
        self::assertContains(Nav::to('bs'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('chv', 'show', $channel->id), chat: self::OWNER));
        self::assertContains(Nav::to('ch', 'v'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('ch', 'v'), chat: self::OWNER));
        self::assertContains(Nav::to('bs'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('ch'), chat: self::OWNER));
        self::assertContains(Nav::to('hb'), $this->callbacks(0), 'from the hub: back to the hub');

        $this->send($this->tap(Nav::to('rg', 'show', 'from.bs'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('rgt'), chat: self::OWNER));
        self::assertContains(Nav::to('rg', 'v'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('rg', 'v'), chat: self::OWNER));
        self::assertContains(Nav::to('bs'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('ba', 'show', 'from.bs'), chat: self::OWNER));
        self::assertStringContainsString('مدیران ربات', $this->said()[0]);
        self::assertContains(Nav::to('bs'), $this->callbacks(0));
    }

    public function testAListOfNumbersIsSentAsTypedAndAPartThatIsNoNumberRefused(): void
    {
        $this->owner();
        $this->botSettings('wallet', ['topup_presets' => [50_000]]);

        foreach (['پنجاه هزار', '50000 1oo000'] as $typed) {
            $this->send($this->tap(Nav::to('bsw', 'e', 1), chat: self::OWNER));
            $this->send($this->message($typed, chat: self::OWNER));
            self::assertSame([50_000], $this->settings()['topup_presets'], "«{$typed}»: refused whole, nothing trimmed");
            self::assertStringStartsWith('<b>⚠️', $this->said()[0]);
        }
    }

    public function testTheExtrasRefusalSaysWhichPartFailed(): void
    {
        $this->owner();
        $this->botSettings('extras', ['extras_enabled' => true]);

        $this->send($this->tap(Nav::to('bsx', 'e', 2), chat: self::OWNER));
        $this->send($this->message('10=30000، بیست', chat: self::OWNER));
        self::assertStringContainsString('«بیست» خوانده نشد', $this->said()[0]);
    }

    public function testTheOwnerSavesAnAgentShopsCardInThatShop(): void
    {
        $this->owner();
        $shop = $this->agentBot();
        $present = fn(): array => CurrentBot::run($shop, fn(): array => $this->service(BotSettingsScreen::class)->present());
        self::assertFalse($present()['expiry_reminder'] ?? true);

        $this->send($this->tap(Nav::to('shop', 'pick', $shop->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('rgt'), chat: self::OWNER));
        self::assertSame([], array_intersect([Nav::to('rgt', 'e', 8, 0), Nav::to('rgt', 'e', 8, 1)], $this->callbacks(0)), 'an agent\'s group has no agency topic');
        $this->send($this->tap(Nav::to('bsm', 'e', 0, 1), chat: self::OWNER));
        self::assertTrue($present()['expiry_reminder'], 'saved in the shop worked');
        self::assertFalse($this->settings()['expiry_reminder'], 'the main shop\'s untouched');
    }

    public function testATypedValueIsSavedAndAWarningFollowsTheValues(): void
    {
        $this->owner();
        $this->botSettings('general', ['support_contact' => '@old_support']);
        $this->botSettings('payments', ['card_to_support' => true]);

        $this->send($this->tap(Nav::to('bsg', 'e', 8), chat: self::OWNER));
        self::assertStringContainsString('@old_support', $this->said()[0], 'the value now');
        self::assertContains(Nav::to('bsg', 'x', 8), $this->callbacks(0), 'an optional field may be emptied');

        $this->send($this->message('@my_support', chat: self::OWNER));
        self::assertSame('@my_support', $this->service(BotSettings::class)->supportContact());
        self::assertStringContainsString('ذخیره شد', $this->said()[0]);

        $this->send($this->tap(Nav::to('bsg', 'x', 8), chat: self::OWNER));
        self::assertSame('', $this->service(BotSettings::class)->supportContact());
        self::assertStringContainsString('بدون راه ارتباط با پشتیبانی کار نمی‌کند', $this->said()[0], 'the panel\'s warning, as the values now stand');
    }

    public function testAmountsAreReadAsTypedAndTheRulesRefuseWhatTheyRefuse(): void
    {
        $this->owner();

        $this->send($this->tap(Nav::to('bsw', 'e', 1), chat: self::OWNER));
        $this->send($this->message('50000، ۱۰۰۰۰۰', chat: self::OWNER));
        self::assertSame([50_000, 100_000], $this->settings()['topup_presets']);

        $this->send($this->tap(Nav::to('bsw', 'e', 0), chat: self::OWNER));
        $this->send($this->message('۵۰ هزار تومان', chat: self::OWNER));
        self::assertSame(50_000, $this->settings()['topup_min']);

        $this->send($this->tap(Nav::to('bsw', 'e', 0), chat: self::OWNER));
        $this->send($this->message('10', chat: self::OWNER));
        self::assertSame(50_000, $this->settings()['topup_min'], 'below the least the panel takes: nothing kept');
        self::assertStringContainsString('⚠️', $this->said()[0], 'the refusal said over the question, which still waits');
    }

    public function testTheAmountsOfferedAreTypedWithTheirPrices(): void
    {
        $this->owner();

        $this->send($this->tap(Nav::to('bsx', 'e', 0, 1), chat: self::OWNER));
        self::assertTrue($this->settings()['extras_enabled']);
        self::assertContains(Nav::to('bsx', 'e', 2), $this->buttons(), 'the price list, shown while extras are sold');

        $this->send($this->tap(Nav::to('bsx', 'e', 2), chat: self::OWNER));
        $this->send($this->message('10=30000، 20', chat: self::OWNER));
        self::assertSame([], $this->settings()['extra_traffic_options'], 'an amount with no price, and no unit price: refused');
        self::assertStringContainsString('قیمت ندارد', implode("\n", $this->said()));

        $this->send($this->message("10=30000\n۲۰=۵۵۰۰۰", chat: self::OWNER));
        self::assertSame([10, 20], array_column($this->settings()['extra_traffic_options'], 'quantity'));
        self::assertStringContainsString('۱۰ گیگابایت · ۳۰٬۰۰۰ تومان', $this->said()[0]);
    }

    public function testAMethodSwitchedOffTakesAutomaticRenewalsWithIt(): void
    {
        $this->owner();

        $this->send($this->tap(Nav::to('bsr', 'e', 4, 1), chat: self::OWNER));
        $this->send($this->tap(Nav::to('bsr', 'e', 6), chat: self::OWNER));
        self::assertContains(Nav::to('bsr', 'pk', 6, 'reset_traffic'), $this->buttons(), 'picked among the methods switched on');
        self::assertNotContains(Nav::to('bsr', 'pk', 6, 'reset_time'), $this->buttons());
        $this->send($this->tap(Nav::to('bsr', 'pk', 6, 'reset_traffic'), chat: self::OWNER));
        self::assertSame('reset_traffic', $this->settings()['renew_default_method']);

        $this->send($this->tap(Nav::to('bsr', 'e', 4, 0), chat: self::OWNER));
        self::assertFalse($this->settings()['renew_reset_traffic']);
        self::assertSame('extend', $this->settings()['renew_default_method'], 'the first still on, in the same save');
    }

    public function testTheReferralRewardsPlanIsPickedAmongThePlans(): void
    {
        $this->owner();
        $plan = $this->plan(['name' => 'جایزه ماهانه']);
        $this->inlineStartMenu();

        $this->send($this->tap(Nav::to('bsf', 'e', 0, 1), chat: self::OWNER));
        $this->send($this->tap(Nav::to('bsf', 'e', 8), chat: self::OWNER));
        $this->send($this->message('3', chat: self::OWNER));
        self::assertSame(0, $this->settings()['referral_reward_every'], 'a count with no plan: refused');
        self::assertStringContainsString('پلن جایزه را انتخاب کنید', $this->said()[0]);

        $this->send($this->tap(Nav::to('bsf', 'e', 7), chat: self::OWNER));
        self::assertContains(Nav::to('bsf', 'pk', 7, $plan->id), $this->buttons());
        $this->send($this->tap(Nav::to('bsf', 'pk', 7, $plan->id), chat: self::OWNER));
        self::assertSame($plan->id, $this->settings()['referral_reward_plan_id']);
        self::assertStringContainsString('جایزه ماهانه', $this->said()[0], 'the plan by its name');
        self::assertStringContainsString('دکمه «زیرمجموعه‌گیری» در منوی ربات نیست', $this->said()[0], 'a program with no way to it on the menu is warned of');

        $this->send($this->tap(Nav::to('bsf', 'e', 8), chat: self::OWNER));
        $this->send($this->message('3', chat: self::OWNER));
        self::assertSame(3, $this->settings()['referral_reward_every']);
    }

    public function testAPatternShowsTheNameItGivesAndTheReportTopicsSwitch(): void
    {
        $this->owner();

        $this->send($this->tap(Nav::to('bsn', 'e', 0), chat: self::OWNER));
        self::assertStringContainsString('{username}', $this->said()[0], 'the variables listed where it is typed');
        $this->send($this->message('shop_{n}', chat: self::OWNER));
        self::assertSame('shop_{n}', $this->settings()['name_pattern']);
        self::assertStringContainsString('نمونه:', $this->said()[0]);

        $this->send($this->tap(Nav::to('rgt', 'e', 0, 0), chat: self::OWNER));
        self::assertFalse($this->settings()['report_purchases']);
    }

    public function testTheQrPictureIsSentSeenAndPutBack(): void
    {
        $this->owner();
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true);

        $this->send($this->tap(Nav::to('bsq'), chat: self::OWNER));
        self::assertStringContainsString('پیش‌فرض', $this->said()[0]);
        $this->send($this->tap(Nav::to('bsq', 'up'), chat: self::OWNER));
        $this->telegram()->on('getFile', static fn(): array => ['file_path' => 'photos/bg.png', 'file_size' => strlen($png)]);
        $this->telegram()->on('bg.png', static fn(): Response => new Response(200, [], $png));
        $this->send($this->message(null, ['photo' => [['file_id' => 'bg-1', 'file_size' => strlen($png)]]], chat: self::OWNER));
        self::assertSame('background.png', $this->service(Settings::class)->get(QrBackground::KEY), 'uploaded through the panel\'s route');
        self::assertStringContainsString('آپلودشده', implode("\n", $this->said()));

        $this->send($this->tap(Nav::to('bsq', 'back'), chat: self::OWNER));
        self::assertContains(Nav::to('bsq', 'gone'), $this->buttons(), 'asked first');
        $this->send($this->tap(Nav::to('bsq', 'gone'), chat: self::OWNER));
        self::assertNull($this->service(Settings::class)->get(QrBackground::KEY));
    }

    public function testAShopsAdminHasNoneOfIt(): void
    {
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('bs'), chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');
        $this->send($this->tap(Nav::to('bsg', 'e', 0, 0, 'y'), chat: 7100));
        self::assertTrue($this->service(BotSettings::class)->enabled(), 'nothing changed');
    }

    /** @return list<string> The buttons of the window as the bot last drew it. */
    private function buttons(): array
    {
        for ($call = count($this->calls()) - 1; $call >= 0; $call--) {
            if ($this->callbacks($call) !== []) {
                return $this->callbacks($call);
            }
        }

        return [];
    }

    /** @return array<string, mixed> Every bot settings field as the panel reads them. */
    private function settings(): array
    {
        return $this->service(BotSettingsScreen::class)->present();
    }

    /** The account OWNER linked as the owner — the bot's admin too, as the link makes it. */
    private function owner(): User
    {
        $owner = User::query()->where('telegram_id', self::OWNER)->first() ?? $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir', 'role' => UserRole::Admin]);
        $this->linkOwner(self::OWNER);

        return $owner;
    }
}
