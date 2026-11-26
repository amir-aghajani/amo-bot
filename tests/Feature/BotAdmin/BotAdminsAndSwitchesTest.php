<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Core\Http\Json;
use App\Modules\Admin\Api\BotSettingsController;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\MiniApps;
use App\Modules\Store\Services\Websites;
use App\Modules\Telegram\Admin\Sections\Core\BotAdminsScreen;
use App\Modules\Telegram\Admin\Sections\Core\HomeScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Telegram\Update\ReceivedUpdates;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tests\BotTestCase;

/**
 * «🛡 مدیران ربات» as the shop's admins listed — each to their page, what an admin has said over them — and one added by
 * their number, Telegram id or handle after a red warning; the bot on and off from the home; «⚡️ کلیدهای اصلی», every
 * switch saved through its own card's group and back to the screen, asked first where its card asks; the main shop's
 * own switches nowhere in an agent's shop; and the role's confirmations saying what it now hands over.
 */
final class BotAdminsAndSwitchesTest extends BotTestCase
{
    /** One of the main shop's admins: the owner's seat. */
    private const STAFF = 7100;

    /** Every switch of «⚡️ کلیدهای اصلی» the main shop has, by its field, in the screen's order. */
    private const SWITCHES = [
        'enabled', 'phone_required', 'join_required',
        'test_enabled', 'extras_enabled', 'custom_enabled', 'claim_enabled', 'transfer_enabled', 'wallet_transfer_enabled',
        'referral_enabled', 'gift_codes_enabled', 'wheel_enabled', 'cashback_enabled', 'lottery_enabled',
        'expiry_reminder', 'traffic_reminder', 'expired_cleanup',
    ];

    /** The switches that hold customers back one way, asked first: field => the state that asks. */
    private const ASKED = ['enabled' => false, 'phone_required' => true, 'join_required' => true];

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheListHasTheShopsAdminsEachToTheirPageAndSaysWhatTheyHave(): void
    {
        $owner = $this->owner(UserRole::Admin);
        $staff = $this->staff();
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);

        $this->send($this->tap(Nav::to('hc'), chat: self::OWNER));
        self::assertContains(Nav::to('ba'), $this->window(), 'in the settings menu');

        $this->send($this->tap(Nav::to('ba'), chat: self::OWNER));
        $screen = $this->screen();
        self::assertStringContainsString('مدیران ربات', $screen);
        self::assertStringContainsString('۲ مدیر ربات', $screen);
        self::assertStringContainsString('با دسترسی مالک', $screen, 'what an admin of the main shop has');
        self::assertStringContainsString('ورود به پنل، حساب‌های تلگرام مالک، آدرس سایت، توکن و نام کاربری ربات اصلی، آدرس Bot API، رمز Webhook، دیتابیس، تنظیمات Session پنل‌ها، پشتیبان‌های ساخته‌شده پیش از نسخه 0.11.0 و برگرداندن به نسخه قبلی که با خود مالک می‌ماند', $screen, 'what stays the owner\'s alone');
        self::assertStringContainsString('به نام خودش ثبت می‌شود', $screen, 'their decisions under their own name');
        self::assertContains(Nav::to('usv', 'show', $owner->id), $this->window());
        self::assertContains(Nav::to('usv', 'show', $staff->id), $this->window());
        self::assertNotContains(Nav::to('usv', 'show', $ali->id), $this->window(), 'a customer is no admin');
        self::assertContains(Nav::to('ba', 'add'), $this->window());
        self::assertContains(BotAdminsScreen::ADD, $this->buttonLabels($this->windowCall()));
        self::assertContains(Nav::to('hc'), $this->window(), 'back to the settings menu');

        // An admin has the owner's seat: the same list.
        $this->send($this->tap(Nav::to('ba'), chat: self::STAFF));
        self::assertContains(Nav::to('usv', 'show', $owner->id), $this->window());

        $this->send($this->tap(Nav::to('usv', 'show', $staff->id), chat: self::OWNER));
        self::assertStringContainsString('<code>' . self::STAFF . '</code>', $this->screen(), 'a row opens the customer\'s page');
    }

    public function testAnAdminIsAddedByTheirHandleAfterARedWarningAndNotTwice(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali', 'username' => 'ali_r']);

        $this->send($this->tap(Nav::to('ba', 'add'), chat: self::OWNER));
        self::assertStringContainsString('افزودن مدیر ربات', $this->screen());
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state(), 'waiting for whom');

        $this->send($this->message('@ALI_R', chat: self::OWNER));
        $screen = $this->screen();
        self::assertStringContainsString('این کاربر مدیر ربات شود؟', $screen);
        self::assertStringContainsString('Ali', $screen);
        self::assertStringContainsString('با دسترسی مالک', $screen, 'what is handed over');
        self::assertStringContainsString('فقط کسی را مدیر کنید که کاملا به او اعتماد دارید', $screen);
        self::assertSame(['callback_data' => Nav::to('ba', 'set', $ali->id), 'style' => 'danger'], array_intersect_key($this->button(Nav::to('ba', 'set', $ali->id)), ['callback_data' => 1, 'style' => 1]), 'red');
        self::assertContains(Nav::to('ba'), $this->window(), '«✖️ انصراف» back to the list');
        self::assertSame(UserRole::Customer, $ali->refresh()->role, 'asked, not made');

        $this->send($this->tap(Nav::to('ba', 'set', $ali->id), chat: self::OWNER));
        self::assertSame(UserRole::Admin, $ali->refresh()->role, 'made through the panel\'s route');
        self::assertSame("✅ \u{2068}Ali\u{2069} مدیر ربات شد", $this->popup());
        self::assertContains(Nav::to('usv', 'show', $ali->id), $this->window(), 'the list again, them in it');

        // By their number now: already one.
        $this->send($this->tap(Nav::to('ba', 'add'), chat: self::OWNER));
        $this->send($this->message('#' . $ali->id, chat: self::OWNER));
        self::assertStringContainsString("\u{2068}Ali\u{2069} همین حالا مدیر ربات است", $this->screen());
        self::assertNotContains(Nav::to('ba', 'set', $ali->id), $this->window());
    }

    public function testATelegramIdFindsTheOneAndSeveralAreOfferedAndNoneIsAskedAgain(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 123456789, 'first_name' => 'Ali']);
        $first = $this->customer(['telegram_id' => 2221, 'first_name' => 'Reza', 'username' => 'reza_a']);
        $second = $this->customer(['telegram_id' => 2222, 'first_name' => 'Reza', 'username' => 'reza_b']);

        $this->send($this->tap(Nav::to('ba', 'add'), chat: self::OWNER));
        $this->send($this->message('۱۲۳۴۵۶۷۸۹', chat: self::OWNER));
        self::assertStringContainsString('این کاربر مدیر ربات شود؟', $this->screen(), 'a Telegram id typed in Persian digits');
        self::assertContains(Nav::to('ba', 'set', $ali->id), $this->window());

        $this->send($this->tap(Nav::to('ba', 'add'), chat: self::OWNER));
        $this->send($this->message('reza', chat: self::OWNER));
        self::assertStringContainsString('کدام کاربر؟', $this->screen());
        self::assertStringContainsString('۲ کاربر', $this->screen());
        self::assertContains(Nav::to('usv', 'show', $first->id), $this->window(), 'each to their page');
        self::assertContains(Nav::to('usv', 'show', $second->id), $this->window());
        self::assertContains(Nav::to('ba', 'add'), $this->window(), 'and the question again');
        self::assertSame(UserRole::Customer, $first->refresh()->role);

        $this->send($this->tap(Nav::to('ba', 'add'), chat: self::OWNER));
        $this->send($this->message('@reza_b', chat: self::OWNER));
        self::assertContains(Nav::to('ba', 'set', $second->id), $this->window(), 'a whole handle is the one it names');

        $this->send($this->tap(Nav::to('ba', 'add'), chat: self::OWNER));
        $this->send($this->message('@nobody_here', chat: self::OWNER));
        self::assertStringContainsString('کاربری با «@nobody_here» در این فروشگاه پیدا نشد', implode("\n", $this->said()));
        self::assertSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state(), 'the question again');
    }

    public function testAWholeHandleIsFoundPastTheSearchsFirstPageAndAskedAboutByWhoExactly(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 3000, 'first_name' => 'Ali', 'username' => 'ali']);
        // Newer customers whose handles hold it, more than the search's first page (the panel's 25, newest first).
        foreach (range(1, 30) as $n) {
            $this->customer(['telegram_id' => 3000 + $n, 'first_name' => 'Ali', 'username' => 'ali_' . $n]);
        }

        $this->send($this->tap(Nav::to('ba', 'add'), chat: self::OWNER));
        $this->send($this->message('@ali', chat: self::OWNER));
        self::assertStringContainsString('این کاربر مدیر ربات شود؟', $this->screen(), 'the one typed, past the first page');
        self::assertContains(Nav::to('ba', 'set', $ali->id), $this->window());
        self::assertStringContainsString("(\u{2068}@ali\u{2069} · <code>3000</code>) مدیر ربات می‌شود", $this->screen(), 'who exactly: the same name is others\' too');
        self::assertStringContainsString('دکمه‌های گروه گزارش‌ها را هم دارد', $this->screen(), 'in the customer page\'s words');

        // A part of it: the several, and a whole handle not offered as the way out again.
        $this->send($this->tap(Nav::to('ba', 'add'), chat: self::OWNER));
        $this->send($this->message('@ali_', chat: self::OWNER));
        self::assertStringContainsString('کدام کاربر؟', $this->screen());
        self::assertStringContainsString('شماره مشتری را با # یا شناسه عددی تلگرامش را بنویسید', $this->screen());
        self::assertSame(UserRole::Customer, $ali->refresh()->role);
    }

    public function testAnAgentsBotListsAndAddsItsOwnAdminsAndSaysWhatTheyHaveThere(): void
    {
        $bot = $this->agentBot();
        $mina = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 8800, 'first_name' => 'Mina', 'role' => UserRole::Admin]));
        $omid = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 8801, 'first_name' => 'Omid', 'username' => 'omid_k']));
        $main = $this->staff();

        $this->sendTo($bot, $this->tap(Nav::to('ba'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('نماینده در ربات خودش', $this->screen(), 'what an admin of an agent\'s bot has');
        self::assertStringNotContainsString('با دسترسی مالک', $this->screen());
        self::assertContains(Nav::to('usv', 'show', $mina->id), $this->window());
        self::assertNotContains(Nav::to('usv', 'show', $main->id), $this->window(), 'the main shop\'s admins are not theirs');

        $this->sendTo($bot, $this->tap(Nav::to('ba', 'add'), chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->message('@omid_k', chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('به همه فروشگاه این ربات دسترسی پیدا می‌کند', $this->screen());
        $this->sendTo($bot, $this->tap(Nav::to('ba', 'set', $omid->id), chat: self::AGENT_TELEGRAM_ID));
        self::assertSame(UserRole::Admin, CurrentBot::run($bot, static fn(): UserRole => $omid->refresh()->role));

        // An admin of theirs works the same list, with the agent's seat.
        $this->sendTo($bot, $this->tap(Nav::to('ba'), chat: 8800));
        self::assertContains(Nav::to('usv', 'show', $omid->id), $this->window());

        // The main shop's customers — the agent's own account there among them — are none of theirs: neither found nor
        // made admins, by a forged button either.
        $own = User::query()->where('telegram_id', self::AGENT_TELEGRAM_ID)->sole();
        $ali = $this->customer(['telegram_id' => 9900, 'first_name' => 'Ali', 'username' => 'ali_main']);
        foreach ([$own, $ali] as $theirs) {
            $this->sendTo($bot, $this->tap(Nav::to('ba', 'add'), chat: self::AGENT_TELEGRAM_ID));
            $this->sendTo($bot, $this->message('#' . $theirs->id, chat: self::AGENT_TELEGRAM_ID));
            self::assertStringContainsString('در این فروشگاه پیدا نشد', implode("\n", $this->said()));
            $this->sendTo($bot, $this->tap(Nav::to('ba', 'set', $theirs->id), chat: self::AGENT_TELEGRAM_ID));
            self::assertNotNull($this->popup(), 'the panel\'s refusal, said');
            self::assertNotContains('editMessageText', $this->calls(), 'the window left as it was');
            $this->sendTo($bot, $this->tap(Nav::to('usv', 'rs', $theirs->id, 'admin'), chat: self::AGENT_TELEGRAM_ID));
            self::assertNotNull($this->popup());
            self::assertSame(UserRole::Customer, $theirs->refresh()->role, "#{$theirs->id}: still a customer of the main shop");
        }
        $this->sendTo($bot, $this->tap(Nav::to('ba', 'add'), chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->message('@ali_main', chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('در این فروشگاه پیدا نشد', implode("\n", $this->said()), 'by their handle neither');
    }

    public function testTheHomeSaysTheBotIsOnAndTurnsItOffAfterAskingAndOnAtOnce(): void
    {
        $this->owner(UserRole::Admin);

        $this->send($this->message('/admin', chat: self::OWNER));
        $home = $this->said()[0];
        self::assertStringContainsString("👑 مالک\n🟢 ربات روشن است", strip_tags($home), 'right under the first line');
        self::assertContains(Nav::to(Screens::HOME, 'bot', 0), $this->callbacks(0));
        self::assertContains(HomeScreen::TURN_OFF, $this->buttonLabels(0));
        self::assertContains(Nav::to('sw', 'show', 'from.h'), $this->callbacks(0), '«⚡️ کلیدهای اصلی» beside it');
        self::assertSame([['text' => '🔄 تازه‌سازی', 'callback_data' => Nav::to(Screens::HOME)]], $this->inlineKeyboard(0)[count($this->inlineKeyboard(0)) - 1]);

        // Off holds every customer back: asked first, in the card's own words.
        $this->send($this->tap(Nav::to(Screens::HOME, 'bot', 0), chat: self::OWNER));
        self::assertTrue($this->service(BotSettings::class)->enabled(), 'asked, not done');
        $asked = $this->screen();
        self::assertStringContainsString('ربات خاموش شود؟', $asked);
        self::assertContains(Nav::to(Screens::HOME, 'bot', 0, 'y'), $this->window());
        self::assertContains(Nav::to(Screens::HOME), $this->window(), '«✖️ انصراف» back home');
        self::assertSame('danger', $this->button(Nav::to(Screens::HOME, 'bot', 0, 'y'))['style'] ?? null);
        $this->send($this->tap(Nav::to('bsg', 'e', 0, 0), chat: self::OWNER));
        self::assertSame(self::detail($this->screen()), self::detail($asked), 'the words «عمومی» asks with');

        $this->send($this->tap(Nav::to(Screens::HOME, 'bot', 0, 'y'), chat: self::OWNER));
        self::assertFalse($this->service(BotSettings::class)->enabled(), 'saved through the panel\'s route');
        self::assertSame('⏸ ربات خاموش شد', $this->popup());
        self::assertStringContainsString('🔴 <b>ربات خاموش است</b>', $this->screen());
        self::assertStringContainsString('ربات فعلا غیرفعال است', $this->screen());
        self::assertContains(Nav::to(Screens::HOME, 'bot', 1), $this->window());
        self::assertContains(HomeScreen::TURN_ON, $this->buttonLabels($this->windowCall()));

        $this->send($this->tap(Nav::to(Screens::HOME, 'bot', 0, 'y'), chat: self::OWNER));
        self::assertStringContainsString('همین حالا خاموش است', (string) $this->popup(), 'a stale button sets what it said, never the opposite');
        self::assertFalse($this->service(BotSettings::class)->enabled());

        $this->send($this->tap(Nav::to(Screens::HOME, 'bot', 1), chat: self::OWNER));
        self::assertTrue($this->service(BotSettings::class)->enabled(), 'on at one tap');
        self::assertSame('✅ ربات روشن شد', $this->popup());
        self::assertStringContainsString('🟢 ربات روشن است', $this->screen());

        // «⚡️ کلیدهای اصلی» opened from the home goes back there, across its own switches.
        $this->send($this->tap(Nav::to('sw', 'show', 'from.h'), chat: self::OWNER));
        self::assertNotContains(Nav::to('hc'), $this->window());
        $this->send($this->tap(Nav::to('sw', 't', 'transfer_enabled', 0), chat: self::OWNER));
        self::assertNotContains(Nav::to('hc'), $this->window(), 'still, after a switch');
        self::assertContains(Nav::to(Screens::HOME), $this->window());
    }

    public function testTheHomeSaysNothingOfTheBotWhenThePanelWillNot(): void
    {
        $this->owner(UserRole::Admin);
        $this->swap(BotSettingsController::class, new class {
            public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                return Json::error($response, 'در دسترس نیست.', 503);
            }
        });

        $this->send($this->message('/admin', chat: self::OWNER));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0]);
        self::assertStringNotContainsString('ربات روشن است', $this->said()[0]);
        self::assertStringNotContainsString('ربات خاموش است', $this->said()[0]);
        self::assertNotContains(Nav::to(Screens::HOME, 'bot', 0), $this->callbacks(0));
        self::assertNotContains(Nav::to(Screens::HOME, 'bot', 1), $this->callbacks(0));
    }

    public function testTheHomeTurnsAnAgentsBotOffAndOnAndNeverTheMainOne(): void
    {
        $bot = $this->agentBot();

        $this->sendTo($bot, $this->message('/admin', chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString("🤝 نماینده\n🟢 ربات روشن است", strip_tags($this->said()[0]));
        $this->sendTo($bot, $this->tap(Nav::to(Screens::HOME, 'bot', 0), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('ربات خاموش شود؟', $this->screen());
        $this->sendTo($bot, $this->tap(Nav::to(Screens::HOME, 'bot', 0, 'y'), chat: self::AGENT_TELEGRAM_ID));
        self::assertFalse(CurrentBot::run($bot, fn(): bool => $this->settings()['enabled'] === true), 'their bot');
        self::assertTrue($this->service(BotSettings::class)->enabled(), 'not the main shop\'s');
        self::assertStringContainsString('🔴 <b>ربات خاموش است</b>', $this->screen());

        // The owner working the agent's shop: that bot's switch, the main shop's left on.
        $this->owner(UserRole::Admin);
        $this->send($this->tap(Nav::to('shop', 'pick', $bot->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to(Screens::HOME), chat: self::OWNER));
        self::assertStringContainsString('🔴 <b>ربات خاموش است</b>', $this->screen(), 'the agent\'s bot as it stands');
        $this->send($this->tap(Nav::to(Screens::HOME, 'bot', 1), chat: self::OWNER));
        self::assertTrue(CurrentBot::run($bot, fn(): bool => $this->settings()['enabled'] === true));
        self::assertTrue($this->service(BotSettings::class)->enabled());
        $this->send($this->tap(Nav::to(Screens::HOME, 'bot', 0, 'y'), chat: self::OWNER));
        self::assertFalse(CurrentBot::run($bot, fn(): bool => $this->settings()['enabled'] === true));
        self::assertTrue($this->service(BotSettings::class)->enabled(), 'still on');
    }

    public function testEverySwitchSavesThroughItsOwnCardAndComesBack(): void
    {
        $this->owner(UserRole::Admin);

        $this->send($this->tap(Nav::to('hc'), chat: self::OWNER));
        self::assertContains(Nav::to('sw'), $this->window(), 'in the settings menu');
        $this->send($this->tap(Nav::to('sw'), chat: self::OWNER));
        self::assertStringContainsString('کلیدهای اصلی', $this->screen());
        self::assertContains(Nav::to('hc'), $this->window(), 'opened from the settings menu: back there');
        self::assertContains(Nav::to('bs'), $this->window(), 'the way to the full cards');

        foreach (self::SWITCHES as $field) {
            // Each switch past the chat's pace of taps (ReceivedUpdates::FLOOD_UPDATES), as an owner trying them would be.
            Carbon::setTestNow(now()->addSeconds(ReceivedUpdates::FLOOD_SECONDS + 1));
            $before = $this->settings()[$field] === true;
            self::assertContains(Nav::to('sw', 't', $field, (int) !$before), $this->window(), "{$field}: a button carrying the state a tap sets");
            if ((self::ASKED[$field] ?? null) === !$before) {
                $this->send($this->tap(Nav::to('sw', 't', $field, (int) !$before), chat: self::OWNER));
                self::assertSame($before, $this->settings()[$field] === true, "{$field}: asked, not done");
                self::assertContains(Nav::to('sw', 't', $field, (int) !$before, 'y'), $this->window());
                self::assertContains(Nav::to('sw', 'v'), $this->window(), "{$field}: «✖️ انصراف» back to the screen");
                $this->send($this->tap(Nav::to('sw', 't', $field, (int) !$before, 'y'), chat: self::OWNER));
            } else {
                $this->send($this->tap(Nav::to('sw', 't', $field, (int) !$before), chat: self::OWNER));
            }
            self::assertSame(!$before, $this->settings()[$field] === true, "{$field}: saved in its card's group");
            self::assertStringContainsString(!$before ? 'روشن شد' : 'خاموش شد', (string) $this->popup(), $field);
            self::assertStringContainsString('کلیدهای اصلی', $this->screen(), "{$field}: back to the screen");
            self::assertContains(Nav::to('sw', 't', $field, (int) $before), $this->window(), "{$field}: its button turned the other way");
        }

        $this->send($this->tap(Nav::to('sw', 't', 'transfer_enabled', (int) $this->settings()['transfer_enabled']), chat: self::OWNER));
        self::assertStringContainsString('همین حالا', (string) $this->popup(), 'a stale button changes nothing');
    }

    public function testTheSwitchesThatHoldCustomersBackAskInTheirCardsWords(): void
    {
        $this->owner(UserRole::Admin);

        $this->send($this->tap(Nav::to('sw', 't', 'enabled', 0), chat: self::OWNER));
        $ours = $this->screen();
        self::assertSame('danger', $this->button(Nav::to('sw', 't', 'enabled', 0, 'y'))['style'] ?? null, 'off is red');
        $this->send($this->tap(Nav::to('bsg', 'e', 0, 0), chat: self::OWNER));
        self::assertSame(self::detail($this->screen()), self::detail($ours), 'the bot off');

        $this->send($this->tap(Nav::to('sw', 't', 'phone_required', 1), chat: self::OWNER));
        $ours = $this->screen();
        $this->send($this->tap(Nav::to('bsg', 'e', 1, 1), chat: self::OWNER));
        self::assertSame(self::detail($this->screen()), self::detail($ours), 'the phone rule on');

        $this->send($this->tap(Nav::to('sw', 't', 'join_required', 1), chat: self::OWNER));
        $ours = $this->screen();
        self::assertStringContainsString('لیست هنوز خالی است', $ours, 'no channel yet, said');
        $this->send($this->tap(Nav::to('ch', 'join', 1), chat: self::OWNER));
        self::assertSame(self::detail($this->screen()), self::detail($ours), 'the channels\' rule on');

        self::assertTrue($this->service(BotSettings::class)->enabled());
        self::assertFalse($this->settings()['phone_required']);
        self::assertFalse($this->settings()['join_required']);
    }

    public function testTheWebsiteAndTheMiniAppSwitchThroughTheirOwnPages(): void
    {
        $this->owner(UserRole::Admin);

        $this->send($this->tap(Nav::to('sw'), chat: self::OWNER));
        self::assertContains(Nav::to('sw', 't', 'web', 1), $this->window());
        self::assertContains(Nav::to('sw', 't', 'app', 1), $this->window());

        $this->send($this->tap(Nav::to('sw', 't', 'web', 1), chat: self::OWNER));
        self::assertSame(Websites::URL_NEEDED, $this->popup(), 'refused as its card is refused: no address yet');
        self::assertFalse(Website::query()->sole()->enabled);

        $this->send($this->tap(Nav::to('wsc', 'e', 1), chat: self::OWNER));
        $this->send($this->message('https://shop.example', chat: self::OWNER));
        $this->send($this->tap(Nav::to('sw', 't', 'web', 1), chat: self::OWNER));
        self::assertTrue(Website::query()->sole()->enabled, 'PATCH /website');
        self::assertContains(Nav::to('sw', 't', 'web', 0), $this->window());

        $this->send($this->tap(Nav::to('sw', 't', 'app', 1), chat: self::OWNER));
        self::assertStringContainsString('فقط از آدرس https باز می‌کند', (string) $this->popup(), 'refused as its card is refused: the shop\'s address no https one');
        self::assertFalse($this->service(MiniApps::class)->current()->refresh()->enabled);

        $this->config(['app.url' => 'https://shop.example']);
        $this->send($this->tap(Nav::to('sw', 't', 'app', 1), chat: self::OWNER));
        self::assertTrue($this->service(MiniApps::class)->current()->refresh()->enabled, 'PATCH /mini-app');
        self::assertContains(Nav::to('sw', 't', 'app', 0), $this->window());
    }

    public function testTheMainShopsOwnSwitchesAreNowhereInAnAgentsShop(): void
    {
        $bot = $this->agentBot();

        $this->sendTo($bot, $this->tap(Nav::to('sw'), chat: self::AGENT_TELEGRAM_ID));
        $offered = $this->window();
        self::assertContains(Nav::to('sw', 't', 'enabled', 0), $offered, 'their bot\'s own switch');
        self::assertContains(Nav::to('sw', 't', 'test_enabled', 1), $offered);
        self::assertContains(Nav::to('sw', 't', 'web', 1), $offered);
        foreach (['claim_enabled', 'custom_enabled'] as $field) {
            self::assertSame([], array_filter($offered, static fn(string $data): bool => str_starts_with($data, Nav::to('sw', 't', $field))), "{$field}: the main shop's alone");
        }

        // A forged tap saves nothing either.
        $this->sendTo($bot, $this->tap(Nav::to('sw', 't', 'claim_enabled', 1), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('کلیدهای اصلی', $this->screen());
        self::assertFalse(CurrentBot::run(Bot::MAIN, fn(): bool => $this->settings()['claim_enabled'] === true));

        // Their switches are their bot's.
        $this->sendTo($bot, $this->tap(Nav::to('sw', 't', 'test_enabled', 1), chat: self::AGENT_TELEGRAM_ID));
        self::assertTrue(CurrentBot::run($bot, fn(): bool => $this->settings()['test_enabled'] === true));
        self::assertFalse($this->settings()['test_enabled'] === true, 'not the main shop\'s');

        // The owner working the agent's shop: the same.
        $this->owner(UserRole::Admin);
        $this->send($this->tap(Nav::to('shop', 'pick', $bot->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('sw'), chat: self::OWNER));
        self::assertContains(Nav::to('sw', 't', 'test_enabled', 0), $this->window(), 'the agent\'s shop as it stands');
        self::assertSame([], array_filter($this->window(), static fn(string $data): bool => str_starts_with($data, Nav::to('sw', 't', 'claim_enabled')) || str_starts_with($data, Nav::to('sw', 't', 'custom_enabled'))));
    }

    public function testTheCustomersPageSaysWhatTheRoleHandsOverInTheShopItIsIn(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);

        $this->send($this->tap(Nav::to('usv', 'role', $ali->id, 'admin'), chat: self::OWNER));
        $screen = $this->screen();
        self::assertStringContainsString('این کاربر مدیر ربات شود؟', $screen);
        self::assertStringContainsString('با دسترسی مالک', $screen);
        self::assertStringContainsString('فقط کسی را مدیر کنید که کاملا به او اعتماد دارید', $screen);
        self::assertStringNotContainsString('کارهای روزانه', $screen, 'no daily work alone any more');
        self::assertSame('danger', $this->button(Nav::to('usv', 'rs', $ali->id, 'admin'))['style'] ?? null);

        // An older window's button for a role that already stands: said, the page again.
        $staff = $this->staff();
        $this->send($this->tap(Nav::to('usv', 'role', $staff->id, 'admin'), chat: self::OWNER));
        self::assertSame("\u{2068}Staff\u{2069} همین حالا مدیر ربات است", $this->popup());
        self::assertNotContains(Nav::to('usv', 'rs', $staff->id, 'admin'), $this->window());
        self::assertContains(Nav::to('usv', 'role', $staff->id, 'customer'), $this->window(), 'their page');

        $bot = $this->agentBot();
        $omid = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 8801, 'first_name' => 'Omid']));
        $this->sendTo($bot, $this->tap(Nav::to('usv', 'role', $omid->id, 'admin'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('نماینده در ربات خودش', $this->screen());
        self::assertStringNotContainsString('با دسترسی مالک', $this->screen());
    }

    public function testTheReportGroupsCardSaysWhatTheRoleHandsOverInRed(): void
    {
        $threads = $this->reportGroup();
        $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);
        $ali = $this->customer(['telegram_id' => 7070, 'username' => 'ali']);

        $this->send($this->groupTap("um:r:{$ali->id}", 5252, $threads['users']));
        $text = $this->params(0)['text'];
        self::assertStringContainsString('❓ <b>مدیر ربات شود؟</b> مدیر ربات با «⚙️ مدیریت» در ربات، همه مدیریت فروشگاه را با دسترسی مالک دارد', $text);
        self::assertStringContainsString('فروشگاه هر نماینده؛ جز ورود به پنل، حساب‌های تلگرام مالک', $text, '«🛡 مدیران ربات»\'s words: what stays the owner\'s alone');
        self::assertStringContainsString('فقط کسی را مدیر کنید که کاملا به او اعتماد دارید', $text);
        $yes = array_values(array_filter(array_merge(...$this->inlineKeyboard(0)), static fn(array $button): bool => ($button['callback_data'] ?? null) === "um:rs:{$ali->id}:admin"));
        self::assertSame('danger', $yes[0]['style'] ?? null, 'its yes red');
        self::assertSame(UserRole::Customer, $ali->refresh()->role, 'asked, not made');
    }

    private function staff(): User
    {
        return $this->customer(['telegram_id' => self::STAFF, 'first_name' => 'Staff', 'role' => UserRole::Admin]);
    }

    /**
     * Every bot settings field of the shop the test is in.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return $this->service(BotSettingsScreen::class)->present();
    }

    /** @return list<string> The window's buttons, as the last screen drew them. */
    private function window(): array
    {
        return $this->callbacks($this->windowCall());
    }

    /** What the window says now. */
    private function screen(): string
    {
        return $this->params($this->windowCall())['text'] ?? '';
    }

    /**
     * The window's button of `$data`, as Telegram has it.
     *
     * @return array<string, mixed>
     */
    private function button(string $data): array
    {
        foreach (array_merge(...$this->inlineKeyboard($this->windowCall())) as $button) {
            if (($button['callback_data'] ?? null) === $data) {
                return $button;
            }
        }
        self::fail("No button {$data} in the window.");
    }

    /** A confirmation's words without its title and the path it was asked from (when it has one): what it says the change does. */
    private static function detail(string $text): string
    {
        $lines = array_slice(explode("\n", $text), 1);
        if (str_starts_with($lines[0] ?? '', '<i>')) {
            array_shift($lines);
        }
        $detail = implode("\n", $lines);
        self::assertNotSame('', $detail, 'a confirmation says what it does');

        return $detail;
    }
}
