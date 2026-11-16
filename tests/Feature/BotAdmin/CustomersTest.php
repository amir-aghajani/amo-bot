<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Telegram\Admin\Door\BotAdminDoor;
use App\Modules\Telegram\Admin\Sections\Customers\Customer;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use Tests\BotTestCase;

/**
 * «👥 کاربران» in the bot's management (Telegram\Admin\Sections\Customers): the list with the panel's pills, search, group
 * and order, a customer's page with every card of the panel's — wallet, groups, website sign-in, a message —, its ban,
 * role, own discount and phone rule, each through the panel's own route; and what a shop's admin sees there: the daily
 * work, the grants the owner gives, and a bot admin's or an agent's account only to look at.
 */
final class CustomersTest extends BotTestCase
{
    private const STAFF = 7100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheOwnerFindsACustomerByThePillsAndTheSearchAndOpensTheirPage(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->wallet($this->customer(['telegram_id' => 1111, 'first_name' => 'Ali', 'username' => 'ali_r', 'phone' => '+989121234567']), '50000');
        $sara = $this->customer(['telegram_id' => 2222, 'first_name' => 'Sara', 'status' => UserStatus::Banned]);

        $this->send($this->tap(Nav::to('hl'), chat: self::OWNER));
        self::assertContains(Nav::to('us'), $this->window(), 'in the sales menu');

        $this->send($this->tap(Nav::to('us'), chat: self::OWNER));
        self::assertStringContainsString('کاربران', $this->screen());
        self::assertContains(Nav::to('usv', 'show', $ali->id), $this->window());
        self::assertContains(Nav::to('us', 'f', 'status', 'banned'), $this->window(), 'the panel\'s status pill');
        self::assertContains(Nav::to('usf', 'show', 'g'), $this->window(), 'its group select');

        $this->send($this->tap(Nav::to('us', 'f', 'status', 'banned'), chat: self::OWNER));
        self::assertContains(Nav::to('usv', 'show', $sara->id), $this->window());
        self::assertNotContains(Nav::to('usv', 'show', $ali->id), $this->window());

        $this->send($this->tap(Nav::to('us', 'f', 'status', '_'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('us', 'search'), chat: self::OWNER));
        $this->send($this->message('ali_r', chat: self::OWNER));
        self::assertContains(Nav::to('usv', 'show', $ali->id), $this->window(), 'found by the handle typed');
        self::assertNotContains(Nav::to('usv', 'show', $sara->id), $this->window());

        $this->send($this->tap(Nav::to('usv', 'show', $ali->id), chat: self::OWNER));
        $page = $this->screen();
        self::assertStringContainsString('Ali', $page);
        self::assertStringContainsString('۵۰٬۰۰۰ تومان', $page, 'their wallet');
        self::assertStringContainsString('+989121234567', $page, 'their phone');
        foreach ([Nav::to('usw', 'show', $ali->id), Nav::to('usg', 'show', $ali->id), Nav::to('usa', 'show', $ali->id), Nav::to('usm', 'show', $ali->id), Nav::to('uso', 'new', $ali->id), Nav::to('usv', 'role', $ali->id, 'admin'), Nav::to('usv', 'ban', $ali->id), Nav::to('usv', 'disc', $ali->id), Nav::to('usls', 'show', $ali->id)] as $button) {
            self::assertContains($button, $this->window(), $button);
        }
    }

    public function testTheGroupAndTheOrderNarrowTheList(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $sara = $this->wallet($this->customer(['telegram_id' => 2222, 'first_name' => 'Sara']), '90000');
        $vip = $this->customerGroup('VIP', [$ali]);

        $this->send($this->tap(Nav::to('usf', 'show', 'g'), chat: self::OWNER));
        self::assertContains(Nav::to('usf', 'g', $vip->id), $this->window());
        $this->send($this->tap(Nav::to('usf', 'g', $vip->id), chat: self::OWNER));
        self::assertStringContainsString('· گروه: VIP', $this->screen(), 'the group in force, in the line of how many');
        self::assertContains(Nav::to('usv', 'show', $ali->id), $this->window());
        self::assertNotContains(Nav::to('usv', 'show', $sara->id), $this->window());

        $this->send($this->tap(Nav::to('usf', 'g', 0), chat: self::OWNER));
        $this->send($this->tap(Nav::to('usf', 's', 'balance', 'desc'), chat: self::OWNER));
        $rows = array_values(array_filter($this->window(), static fn(string $data): bool => str_starts_with($data, Nav::to('usv'))));
        self::assertSame([Nav::to('usv', 'show', $sara->id), Nav::to('usv', 'show', $ali->id)], array_slice($rows, 0, 2), 'the most money first');
        self::assertStringContainsString('· ترتیب: بیشترین موجودی اول', $this->screen(), 'the order in force, in the line of how many');
        self::assertContains('↕️ ترتیب', $this->buttonLabels($this->buttonsCall()), 'its button says only what it picks');
    }

    public function testEachRowSaysTheFactTheListIsOrderedBy(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->wallet($this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']), '50000');
        $server = $this->fakeServer();
        $this->purchaseOrder($ali, $this->plan([], $server), $server);

        $this->send($this->tap(Nav::to('us'), chat: self::OWNER));
        self::assertStringContainsString("\n۲ کاربر", $this->screen(), 'how many (Ali and the owner), by their noun');
        self::assertContains('👤 ' . Customer::isolate('Ali') . ' · ۵۰٬۰۰۰ تومان', $this->buttonLabels($this->buttonsCall()), 'their wallet at first');

        $this->send($this->tap(Nav::to('usf', 's', 'orders', 'desc'), chat: self::OWNER));
        self::assertContains('👤 ' . Customer::isolate('Ali') . ' · ۱ سفارش', $this->buttonLabels($this->buttonsCall()), 'ordered by orders: their orders');
    }

    public function testACustomersPageLeadsWithTheQuickActionsAndEndsWithTheBanInRed(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali', 'username' => 'ali_r']);

        $this->send($this->tap(Nav::to('usv', 'show', $ali->id), chat: self::OWNER));
        self::assertStringContainsString('شناسه تلگرام: <code>1111</code>', $this->screen(), 'their Telegram id, to copy');
        $rows = $this->inlineKeyboard($this->buttonsCall());
        $first = array_column($rows[0], 'callback_data');
        self::assertEqualsCanonicalizing([Nav::to('usm', 'show', $ali->id), Nav::to('usw', 'show', $ali->id)], $first, 'a message and the wallet first');
        self::assertSame('primary', array_values(array_filter($rows[0], static fn(array $button): bool => $button['callback_data'] === Nav::to('usm', 'show', $ali->id)))[0]['style'] ?? null);
        $last = $rows[count($rows) - 2];
        self::assertSame([['text' => '⛔️ مسدود کردن', 'callback_data' => Nav::to('usv', 'ban', $ali->id), 'style' => 'danger']], $last, 'the ban last, red');
        self::assertContains(Words::REFRESH, array_column($rows[count($rows) - 1], 'text'), 'refreshed from the way around');
    }

    public function testACustomersServicesNarrowByState(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $server = $this->fakeServer();
        $plan = $this->plan([], $server);
        $running = $this->subscription($ali, $plan, $server, 'ali_1');
        $ended = $this->subscription($ali, $plan, $server, 'ali_2', ['status' => SubscriptionStatus::Expired]);

        $this->send($this->tap(Nav::to('usls', 'show', $ali->id), chat: self::OWNER));
        self::assertContains(Nav::to('sbv', 'show', $running->id, 'from.usls'), $this->window());
        self::assertContains(Nav::to('sbv', 'show', $ended->id, 'from.usls'), $this->window());

        $this->send($this->tap(Nav::to('usls', 'f', 'status', 'expired'), chat: self::OWNER));
        self::assertContains(Nav::to('sbv', 'show', $ended->id, 'from.usls'), $this->window());
        self::assertNotContains(Nav::to('sbv', 'show', $running->id, 'from.usls'), $this->window(), 'narrowed to the ended ones');

        $sara = $this->customer(['telegram_id' => 2222, 'first_name' => 'Sara']);
        $this->send($this->tap(Nav::to('usls', 'show', $sara->id), chat: self::OWNER));
        self::assertContains(Words::CHIP . 'همه', $this->buttonLabels($this->buttonsCall()), 'another customer\'s list opens unfiltered');
    }

    public function testTheOwnerCreditsAWalletOnceWithANote(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);

        $this->send($this->tap(Nav::to('usw', 'show', $ali->id), chat: self::OWNER));
        self::assertStringContainsString('هنوز تراکنشی ندارد', $this->screen());
        $this->send($this->tap(Nav::to('usw', 'add', $ali->id), chat: self::OWNER));
        $this->send($this->message('۵۰ هزار تومان', chat: self::OWNER));
        $this->send($this->message('هدیه تولد', chat: self::OWNER));
        self::assertStringContainsString('۵۰٬۰۰۰ تومان', $this->screen(), 'said once more before it goes');
        self::assertStringContainsString('موجودی بعد از آن: <b>۵۰٬۰۰۰ تومان</b>', $this->screen(), 'with the balance it leaves');
        self::assertContains(Nav::to('usw', 'do', $ali->id), $this->window());
        self::assertSame('0.00', $ali->balance(), 'nothing yet');

        $this->send($this->tap(Nav::to('usw', 'do', $ali->id), chat: self::OWNER));
        self::assertSame('50000.00', $ali->balance());
        self::assertStringContainsString('افزایش موجودی ثبت شد', (string) $this->popup());
        self::assertStringContainsString('هدیه تولد', $this->screen(), 'the ledger');

        $this->send($this->tap(Nav::to('usw', 'do', $ali->id), chat: self::OWNER));
        self::assertSame('50000.00', $ali->balance(), 'pressed twice, made once');
    }

    public function testADebitPastTheBalanceIsSaidBeforeItIsSentAndRefused(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $this->wallet($ali, '20000');

        $this->send($this->tap(Nav::to('usw', 'sub', $ali->id), chat: self::OWNER));
        $this->send($this->message('۵۰ هزار تومان', chat: self::OWNER));
        $this->send($this->tap(Nav::to('usw', 'skip', $ali->id), chat: self::OWNER));
        self::assertStringContainsString('بیشتر از موجودی فعلی کم نمی‌شود', $this->screen(), 'said before «✔️»');
        self::assertStringNotContainsString('موجودی بعد از آن', $this->screen(), 'no balance after a debit that cannot be made');
        self::assertContains(Nav::to('usw', 'amt', $ali->id), $this->window(), 'the amount a tap away');

        $this->send($this->tap(Nav::to('usw', 'do', $ali->id), chat: self::OWNER));
        self::assertSame('20000.00', $ali->balance(), 'the panel refuses it');
        self::assertStringContainsString('از این بیشتر نمی‌شود کم کرد', (string) $this->popup());

        $this->send($this->tap(Nav::to('usw', 'amt', $ali->id), chat: self::OWNER));
        $this->send($this->message('۱۵۰۰۰', chat: self::OWNER));
        self::assertStringContainsString('موجودی بعد از آن: <b>۵٬۰۰۰ تومان</b>', $this->screen());
        self::assertStringNotContainsString('بیشتر از موجودی فعلی', $this->screen());
        $this->send($this->tap(Nav::to('usw', 'do', $ali->id), chat: self::OWNER));
        self::assertSame('5000.00', $ali->balance());
    }

    public function testAShopsAdminAdjustsAWalletOnlyWithTheGrant(): void
    {
        $this->staff();
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);

        $this->send($this->tap(Nav::to('usv', 'show', $ali->id), chat: self::STAFF));
        self::assertContains(Nav::to('usw', 'show', $ali->id), $this->window(), 'the wallet reads');
        self::assertNotContains(Nav::to('uso', 'new', $ali->id), $this->window(), 'no order by hand without its grant');
        self::assertNotContains(Nav::to('usv', 'disc', $ali->id), $this->window(), 'the own discount is the owner\'s');
        self::assertNotContains(Nav::to('usv', 'role', $ali->id, 'admin'), $this->window(), 'and the role');
        self::assertContains(Nav::to('usv', 'ban', $ali->id), $this->window(), 'the daily work');

        $this->send($this->tap(Nav::to('usw', 'show', $ali->id), chat: self::STAFF));
        self::assertNotContains(Nav::to('usw', 'add', $ali->id), $this->window());

        // A button of an older screen: the door says no when the change is sent.
        $this->send($this->tap(Nav::to('usw', 'add', $ali->id), chat: self::STAFF));
        $this->send($this->message('20000', chat: self::STAFF));
        $this->send($this->tap(Nav::to('usw', 'skip', $ali->id), chat: self::STAFF));
        $this->send($this->tap(Nav::to('usw', 'do', $ali->id), chat: self::STAFF));
        self::assertSame(BotAdminDoor::NOT_GRANTED, $this->popup());
        self::assertSame('0.00', $ali->balance());

        $this->botSettings('bot_admins', ['bot_admins_grant_wallet' => true]);
        $this->send($this->tap(Nav::to('usw', 'show', $ali->id), chat: self::STAFF));
        self::assertContains(Nav::to('usw', 'add', $ali->id), $this->window(), 'granted');
    }

    public function testAShopsAdminOnlyLooksAtABotAdminsAccount(): void
    {
        $this->staff();
        $boss = $this->customer(['telegram_id' => 3333, 'first_name' => 'Boss', 'role' => UserRole::Admin]);
        $this->customerGroup('VIP');

        $this->send($this->tap(Nav::to('usv', 'show', $boss->id), chat: self::STAFF));
        self::assertStringContainsString(Customer::ADMIN_LOCK, $this->screen());
        foreach ([Nav::to('usv', 'ban', $boss->id), Nav::to('usm', 'show', $boss->id)] as $button) {
            self::assertNotContains($button, $this->window(), $button);
        }

        $this->send($this->tap(Nav::to('usg', 'show', $boss->id), chat: self::STAFF));
        self::assertStringContainsString(Customer::ADMIN_LOCK, $this->screen());
        self::assertSame([], array_filter($this->window(), static fn(string $data): bool => str_starts_with($data, Nav::to('usg', 'sw'))), 'no switch');

        // Forged taps (an old window's, hand-made data): the panel's rules refuse each, nothing changes.
        $this->send($this->tap(Nav::to('usv', 'bs', $boss->id, 'banned'), chat: self::STAFF));
        self::assertSame(UserStatus::Active, $boss->refresh()->status, 'a bot admin\'s account is the owner\'s to ban');
        self::assertNotNull($this->popup());
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $this->send($this->tap(Nav::to('usv', 'rs', $ali->id, 'admin'), chat: self::STAFF));
        self::assertSame(UserRole::Customer, $ali->refresh()->role, 'the role is the owner\'s');
        $this->send($this->tap(Nav::to('usv', 'disc', $ali->id), chat: self::STAFF));
        $this->send($this->message('۲۰', chat: self::STAFF));
        self::assertSame(0, $ali->refresh()->discount_percent, 'and the own discount');
    }

    public function testABanIsAskedFirstAndLiftedAtOnce(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);

        $this->send($this->tap(Nav::to('usv', 'ban', $ali->id), chat: self::OWNER));
        self::assertStringContainsString('این کاربر مسدود شود؟', $this->screen());
        self::assertContains(Nav::to('usv', 'bs', $ali->id, 'banned'), $this->window());
        self::assertSame(UserStatus::Active, $ali->refresh()->status, 'asked first');

        $this->send($this->tap(Nav::to('usv', 'bs', $ali->id, 'banned'), chat: self::OWNER));
        self::assertSame(UserStatus::Banned, $ali->refresh()->status);
        self::assertContains(Nav::to('usv', 'bs', $ali->id, 'active'), $this->window(), '«✅ رفع مسدودی»');

        $this->send($this->tap(Nav::to('usv', 'bs', $ali->id, 'active'), chat: self::OWNER));
        self::assertSame(UserStatus::Active, $ali->refresh()->status);
        self::assertStringContainsString('دوباره فعال شد', (string) $this->popup());
    }

    public function testTheOwnerSetsTheOwnDiscountTheRoleAndThePhoneRule(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);

        $this->send($this->tap(Nav::to('usv', 'disc', $ali->id), chat: self::OWNER));
        $this->send($this->message('۱۵۰', chat: self::OWNER));
        self::assertSame(0, $ali->refresh()->discount_percent, 'the panel\'s own check');
        self::assertStringContainsString('دوباره بفرستید', $this->screen(), 'the question waits');
        $this->send($this->message('۱۵', chat: self::OWNER));
        self::assertSame(15, $ali->refresh()->discount_percent);
        self::assertStringContainsString('تخفیف اختصاصی ذخیره شد', $this->screen());

        $this->send($this->tap(Nav::to('usv', 'ph', $ali->id, 1), chat: self::OWNER));
        self::assertTrue($ali->refresh()->phone_exempt);

        $this->send($this->tap(Nav::to('usv', 'role', $ali->id, 'admin'), chat: self::OWNER));
        self::assertStringContainsString('این کاربر مدیر ربات شود؟', $this->screen());
        $this->send($this->tap(Nav::to('usv', 'rs', $ali->id, 'admin'), chat: self::OWNER));
        self::assertSame(UserRole::Admin, $ali->refresh()->role);
        self::assertContains(Nav::to('usv', 'role', $ali->id, 'customer'), $this->window());

        $this->send($this->tap(Nav::to('usv', 'role', $ali->id, 'customer'), chat: self::OWNER));
        self::assertStringNotContainsString('این حساب خود شماست', $this->screen(), 'another\'s account');
        self::assertStringContainsString('و مدیریت فروشگاه از ربات برایش کار نمی‌کند', $this->screen(), 'the panel\'s words: the bot\'s management goes with the role');
    }

    public function testTakingTheRoleFromTheSeatsOwnAccountWarnsFirst(): void
    {
        $own = $this->owner(UserRole::Admin);

        $this->send($this->tap(Nav::to('usv', 'role', $own->id, 'customer'), chat: self::OWNER));
        self::assertStringContainsString('این حساب خود شماست', $this->screen());
        self::assertStringContainsString('گروه گزارش‌ها دیگر به شما جواب نمی‌دهند', $this->screen(), 'what it costs: the report group\'s buttons — the bot\'s conditions never hold the owner');
        self::assertSame(UserRole::Admin, $own->refresh()->role, 'asked first');
        $this->send($this->tap(Nav::to('usv', 'rs', $own->id, 'customer'), chat: self::OWNER));
        self::assertSame(UserRole::Customer, $own->refresh()->role);
    }

    public function testACustomersGroupsSwitchAtATapAndAPartnerGroupAsksTheCatalogue(): void
    {
        $this->staff();
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $vip = $this->customerGroup('VIP');
        $partners = $this->customerGroup('همکاران', [], ['agent_pricing' => true]);

        $this->send($this->tap(Nav::to('usg', 'show', $ali->id), chat: self::STAFF));
        self::assertContains(Nav::to('usg', 'sw', $ali->id, $vip->id, 1), $this->window());
        self::assertContains(Nav::to('usg', 'no', $ali->id), $this->window(), 'the «همکار» group locked without the catalogue');

        $this->send($this->tap(Nav::to('usg', 'sw', $ali->id, $vip->id, 1), chat: self::STAFF));
        self::assertSame([$vip->id], $ali->groups()->get()->modelKeys());
        self::assertContains(Nav::to('usg', 'sw', $ali->id, $vip->id, 0), $this->window(), 'its switch takes them out now');

        $this->send($this->tap(Nav::to('usg', 'sw', $ali->id, $partners->id, 1), chat: self::STAFF));
        self::assertSame([$vip->id], $ali->groups()->get()->modelKeys(), 'the panel refuses it');

        $this->botSettings('bot_admins', ['bot_admins_grant_catalog' => true]);
        $this->send($this->tap(Nav::to('usg', 'sw', $ali->id, $partners->id, 1), chat: self::STAFF));
        self::assertEqualsCanonicalizing([$vip->id, $partners->id], $ali->groups()->get()->modelKeys());
    }

    public function testAMessageIsReadOnceMoreThenSent(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);

        $this->send($this->tap(Nav::to('usm', 'show', $ali->id), chat: self::OWNER));
        $this->send($this->message("سلام\nسرویس شما تمدید شد.", chat: self::OWNER));
        self::assertStringContainsString('سرویس شما تمدید شد', $this->screen());
        self::assertSame([], $this->telegram()->sentTo(1111), 'read once more first');

        $this->send($this->tap(Nav::to('usm', 'send', $ali->id), chat: self::OWNER));
        self::assertCount(1, $this->telegram()->sentTo(1111));
        self::assertStringContainsString('سرویس شما تمدید شد', $this->telegram()->sentTo(1111)[0]);
        self::assertStringContainsString('پیام در تلگرام به کاربر رسید', (string) $this->popup());
    }

    public function testTheWebsiteSignOutAsksTheAccountSecurityGrant(): void
    {
        $this->staff();
        $this->website();
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $this->customerSession($ali);

        $this->send($this->tap(Nav::to('usa', 'show', $ali->id), chat: self::STAFF));
        self::assertStringContainsString('۱ دستگاه', $this->screen());
        self::assertNotContains(Nav::to('usa', 'so', $ali->id), $this->window());

        $this->botSettings('bot_admins', ['bot_admins_grant_account_security' => true]);
        $this->send($this->tap(Nav::to('usa', 'show', $ali->id), chat: self::STAFF));
        self::assertContains(Nav::to('usa', 'so', $ali->id), $this->window());
        $this->send($this->tap(Nav::to('usa', 'so', $ali->id), chat: self::STAFF));
        self::assertContains(Nav::to('usa', 'soy', $ali->id), $this->window(), 'asked first');
        $this->send($this->tap(Nav::to('usa', 'soy', $ali->id), chat: self::STAFF));
        self::assertSame(0, $this->service(CustomerSessions::class)->count($ali));
        self::assertStringContainsString('در هیچ دستگاهی وارد نیست', $this->screen());
    }

    public function testAnotherCountrysNumberIsSaidToBeAskedAgainWhileTheBotTakesIranianOnes(): void
    {
        $this->owner(UserRole::Admin);
        $sam = $this->customer(['telegram_id' => 3333, 'first_name' => 'Sam', 'phone' => '+14155550101']);
        $this->botSettings('general', ['phone_required' => true, 'phone_iran_only' => true]);

        $this->send($this->tap(Nav::to('usv', 'show', $sam->id), chat: self::OWNER));
        self::assertStringContainsString('شماره‌اش ایرانی نیست', $this->screen());

        $this->send($this->tap(Nav::to('usv', 'ph', $sam->id, 1), chat: self::OWNER));
        self::assertStringNotContainsString('شماره‌اش ایرانی نیست', $this->screen(), 'excused, never asked');
        self::assertStringContainsString('معاف از ارسال شماره موبایل', $this->screen());
    }

    public function testACustomersListsLinkEachRowToItsOwnScreen(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $server = $this->fakeServer();
        $plan = $this->plan([], $server);
        $service = $this->subscription($ali, $plan, $server, 'ali_1');
        $order = $this->purchaseOrder($ali, $plan, $server);
        $ticket = $this->ticket($ali);

        $this->send($this->tap(Nav::to('usls', 'show', $ali->id), chat: self::OWNER));
        self::assertContains(Nav::to('sbv', 'show', $service->id, 'from.usls'), $this->window());
        self::assertContains(Nav::to('usv'), $this->window(), 'back to their page');
        $this->send($this->tap(Nav::to('uslo', 'show', $ali->id), chat: self::OWNER));
        self::assertContains(Nav::to('orv', 'show', $order->id, 'from.uslo'), $this->window());
        $this->send($this->tap(Nav::to('uslt', 'show', $ali->id), chat: self::OWNER));
        self::assertContains(Nav::to('tkv', 'show', $ticket->id, 'from.uslt'), $this->window());
        self::assertContains('🟡 #' . Persian::digits((string) $ticket->id) . ' · قطعی اتصال', $this->buttonLabels($this->buttonsCall()), 'as the shop\'s tickets list has its rows');

        $sara = $this->customer(['telegram_id' => 2222, 'first_name' => 'Sara']);
        $this->send($this->tap(Nav::to('usls', 'show', $sara->id), chat: self::OWNER));
        self::assertNotContains(Nav::to('sbv', 'show', $service->id, 'from.usls'), $this->window(), 'another customer\'s list');
    }

    public function testARowOpenedFromACustomersListGoesBackToThatList(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $server = $this->fakeServer();
        $plan = $this->plan([], $server);
        $service = $this->subscription($ali, $plan, $server, 'ali_1');
        $order = $this->purchaseOrder($ali, $plan, $server);
        $payment = $this->cardPayment($order, $this->cardMethod());
        $ticket = $this->ticket($ali);

        foreach ([['uslp', 'pyv', $payment->id, 'py'], ['uslo', 'orv', $order->id, 'or'], ['usls', 'sbv', $service->id, 'sb'], ['uslt', 'tkv', $ticket->id, 'tk']] as [$list, $screen, $id, $shop]) {
            $this->send($this->tap(Nav::to($list, 'show', $ali->id), chat: self::OWNER));
            $this->send($this->tap(Nav::to($screen, 'show', $id, 'from.' . $list), chat: self::OWNER));
            self::assertContains(Nav::to($list), $this->window(), "{$screen}: back to the customer's list it was opened from");
            self::assertNotContains(Nav::to($shop), $this->window(), "{$screen}: not the shop's whole list");

            // Its own buttons (a refresh) keep where it goes back to.
            $this->send($this->tap(Nav::to($screen, 'show', $id), chat: self::OWNER));
            self::assertContains(Nav::to($list), $this->window(), "{$screen}: still back to the customer's list");
        }

        $other = $this->cardPayment($this->purchaseOrder($ali, $plan, $server), $this->cardMethod());
        $this->send($this->tap(Nav::to('py'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pyv', 'show', $other->id), chat: self::OWNER));
        self::assertContains(Nav::to('py'), $this->window(), 'a row opened from the shop\'s list goes back there');
        self::assertNotContains(Nav::to('uslp'), $this->window());
    }

    /** One of the shop's admins (STAFF), who manage it from the bot while «مدیران ربات» lets them in. */
    private function staff(): User
    {
        return $this->customer(['telegram_id' => self::STAFF, 'first_name' => 'Staff', 'role' => UserRole::Admin]);
    }

    /** @return list<string> The window's buttons, as the last screen drew them. */
    private function window(): array
    {
        $calls = $this->calls();
        for ($i = count($calls) - 1; $i >= 0; $i--) {
            if (in_array($calls[$i], ['sendMessage', 'editMessageText'], true) && ($this->params($i)['chat_id'] ?? null) !== '1111') {
                return $this->callbacks($i);
            }
        }

        return [];
    }

    /** What the window says now. */
    private function screen(): string
    {
        $calls = $this->calls();
        for ($i = count($calls) - 1; $i >= 0; $i--) {
            if (in_array($calls[$i], ['sendMessage', 'editMessageText'], true) && ($this->params($i)['chat_id'] ?? null) !== '1111') {
                return $this->params($i)['text'] ?? '';
            }
        }

        return '';
    }
}
