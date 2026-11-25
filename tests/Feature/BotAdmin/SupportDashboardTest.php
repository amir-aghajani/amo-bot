<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Core\Scheduling\Scheduler;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\SellingSuspension;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Telegram\Admin\SeatKind;
use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Sections\Support\Words;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;
use App\Modules\Telegram\BotState;
use App\Modules\Users\Enums\UserRole;
use App\Support\Persian;
use App\Support\PhpVersion;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * «📊 داشبورد» in the bot's management (Sections\Support\Dashboard*): the range's figures against the range before, what
 * needs a hand — each queue a button to its list on the count's tab —, the owner's system state alone, the daily curve
 * by metric and range, the latest orders and customers, and an agent's terms of selling said to them.
 */
final class SupportDashboardTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheOwnerSeesTheFiguresWhatWaitsAndTheSystem(): void
    {
        $this->owner();
        $ali = $this->customer(['first_name' => 'Ali']);
        $ticket = $this->ticket($ali);
        $this->paidByCard($this->topUpOrder($ali, '120000'));

        $this->send($this->message('/admin', chat: 7001));
        self::assertContains(Nav::to('db'), $this->callbacks(0), 'the home leads to it');

        $this->send($this->tap(Nav::to('db'), chat: 7001));
        $said = $this->said()[0];
        self::assertStringContainsString('📊 داشبورد', $said);
        self::assertStringContainsString('۳۰ روز گذشته', $said, 'the panel\'s range at first');
        self::assertStringContainsString('درآمد: <b>۱۲۰٬۰۰۰ تومان</b>', $said);
        self::assertStringContainsString('کاربران جدید', $said);
        self::assertStringContainsString('<b>🔔 در انتظار</b> · ۱ کار', $said);
        self::assertContains('🎧 تیکت‌ها (۱)', $this->buttonLabels(0), 'what waits, a button with its count — not said again in the text');
        self::assertStringNotContainsString('تیکت', $said);
        self::assertSame(1, mb_substr_count($said, Words::NO_BASELINE), 'no figure before: said once, not beside each');
        self::assertContains('● ۳۰ روز', $this->buttonLabels(0), 'the range in force, marked');
        self::assertStringContainsString('وضعیت سیستم', $said, 'the owner\'s system: here it needs fixing');
        self::assertStringContainsString('کارهای زمان‌بندی‌شده هنوز اجرا نشده‌اند', $said);
        self::assertContains(Nav::to('ppv'), $this->callbacks(0), 'the fix a tap away');
        self::assertContains(Nav::to('db'), $this->callbacks(0), 'live: «🔄 تازه‌سازی»');
        self::assertContains(Nav::to('db', 'q', 'open_tickets'), $this->callbacks(0), 'the count leads to its list on its tab');
        self::assertContains(Nav::to('db', 'r', 7), $this->callbacks(0));

        // A search and one customer's narrowing left on the list would hide what the count counted.
        $sara = $this->customer(['telegram_id' => 9001, 'first_name' => 'Sara']);
        $this->send($this->tap(Nav::to('tk', 'user', $sara->id), chat: 7001));
        $this->send($this->tap(Nav::to('tk', 'search'), chat: 7001));
        $this->send($this->message('چیزی که نیست', chat: 7001));

        $this->send($this->tap(Nav::to('db', 'q', 'open_tickets'), chat: 7001));
        self::assertStringContainsString('● 🟡 در انتظار پاسخ', (string) json_encode($this->inlineKeyboard(0), JSON_UNESCAPED_UNICODE), 'on the count\'s tab');
        self::assertStringNotContainsString('🔎', $this->said()[0], 'no search left on it');
        self::assertContains(Nav::to('tkv', 'show', $ticket->id), $this->callbacks(0), 'every one the count counted');

        $this->send($this->tap(Nav::to('db', 'r', 7), chat: 7001));
        self::assertStringContainsString('۷ روز گذشته', $this->said()[0]);
        self::assertContains(Nav::to('db', 'r', 30), $this->callbacks(0));
    }

    public function testEachFigureIsAgainstTheRangeBeforeWhereThatHadOne(): void
    {
        $this->owner();
        $before = $this->customer(['telegram_id' => 9002, 'first_name' => 'Old']);
        $before->forceFill(['created_at' => now()->subDays(40)])->save();
        $this->customer(['telegram_id' => 9003, 'first_name' => 'New']);

        $this->send($this->tap(Nav::to('db'), chat: 7001));
        $said = $this->said()[0];
        self::assertStringContainsString('کاربران جدید: <b>۲</b> · 📈 +۱۰۰٪', $said, 'two this range against one the range before');
        self::assertStringContainsString('درآمد: <b>۰ تومان</b> · —', $said, 'none the range before: «—»');
        self::assertStringContainsString('درصدها نسبت به ۳۰ روز قبل از آن؛ «—»: ' . Words::NO_BASELINE, $said);
        self::assertSame(1, mb_substr_count($said, Words::NO_BASELINE));
    }

    public function testTheSystemIsSaidOnlyWhenSomethingNeedsFixing(): void
    {
        $this->owner();
        $this->service(BotState::class)->webhookSet('https://shop.example/webhooks/telegram/secret');
        $this->service(Scheduler::class)->run(force: true);
        $fixes = [Nav::to('ppw'), Nav::to('ppv'), Nav::to('ppt'), Nav::to('ppu')];

        $this->send($this->tap(Nav::to('db'), chat: 7001));
        self::assertStringNotContainsString('وضعیت سیستم', $this->said()[0], 'all well: nothing said');
        self::assertSame([], array_intersect($fixes, $this->callbacks(0)));

        // A host PHP with known holes: said, as the panel says it over every page.
        PhpVersion::setTestHost('8.4.1');
        $this->send($this->tap(Nav::to('db'), chat: 7001));
        self::assertStringContainsString('وضعیت سیستم', $this->said()[0]);
        self::assertStringContainsString('حفره امنیتی جدی دارد', $this->said()[0]);
    }

    public function testTheCurveIsAMetricAtATimeOverTheRange(): void
    {
        $this->owner();
        $this->paidByCard($this->topUpOrder($this->customer(), '50000'));

        $this->send($this->tap(Nav::to('dbc'), chat: 7001));
        $said = $this->said()[0];
        self::assertStringContainsString('📈 درآمد · ۳۰ روز گذشته', $said);
        self::assertStringContainsString('<b>۵۰٬۰۰۰ تومان</b>', $said);
        self::assertStringContainsString('میانگین روزانه', $said);
        self::assertStringContainsString('روز به روز', $said);
        self::assertStringContainsString('█', $said, 'the best day is the curve\'s top');

        $this->send($this->tap(Nav::to('dbc', 'm', 'orders'), chat: 7001));
        self::assertStringContainsString('📈 سفارش‌ها', $this->said()[0]);

        $this->send($this->tap(Nav::to('dbc', 'r', 90), chat: 7001));
        self::assertStringContainsString('۹۰ روز گذشته', $this->said()[0]);
        self::assertStringContainsString('هفته به هفته', $this->said()[0], 'a long range by its weeks');
    }

    public function testTheLatestOrdersAndCustomers(): void
    {
        $this->owner();
        $sara = $this->customer(['telegram_id' => 9001, 'first_name' => 'Sara', 'username' => 'sara']);
        $order = $this->topUpOrder($sara, '70000');

        $this->send($this->tap(Nav::to('dbr', 'show', 'o'), chat: 7001));
        self::assertStringContainsString('آخرین سفارش‌ها', $this->said()[0]);
        self::assertStringContainsString('۱ سفارش آخر، تازه‌ترین اول', $this->said()[0], 'the latest, not how many there are');
        self::assertContains(Sales::mark(Sales::ORDER_STATUS, 'pending') . ' #' . Persian::digits($order->id) . ' · شارژ کیف پول · ۷۰٬۰۰۰ تومان · Sara', $this->buttonLabels(0), 'a row: its state, number, what, amount and customer');
        self::assertContains(Nav::to('orv', 'show', $order->id), $this->callbacks(0));

        $this->send($this->tap(Nav::to('dbr', 'show', 'u'), chat: 7001));
        self::assertStringContainsString('کاربران جدید', $this->said()[0]);
        self::assertContains(Nav::to('usv', 'show', $sara->id), $this->callbacks(0));
        self::assertContains('👤 Sara · همین حالا', $this->buttonLabels(0));
    }

    public function testAShopsAdminSeesTheDailyWorkWithoutTheSystem(): void
    {
        $admin = $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->message('/admin', chat: 7100));
        self::assertContains(Nav::to('db'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('db'), chat: 7100));

        self::assertStringContainsString('📊 داشبورد', $this->said()[0]);
        self::assertStringContainsString(UiWords::NOTHING_WAITING, $this->said()[0]);
        self::assertStringNotContainsString('وضعیت سیستم', $this->said()[0], 'the installation is the owner\'s');

        $staff = $this->service(PanelBridge::class)->for(new AdminSeat(SeatKind::Staff, new Principal(PrincipalKind::Staff, 'tg:7100', CurrentBot::main(), $admin, []), $admin));
        try {
            $staff->get('/system');
            self::fail('the door let an admin read the system');
        } catch (ApiRefusal $refusal) {
            self::assertSame(404, $refusal->status);
        }
    }

    public function testAnAgentIsToldTheirTermsOfSelling(): void
    {
        $bot = $this->agentBot();
        $bot->forceFill(['selling_suspended_at' => now(), 'suspension_reason' => SellingSuspension::Manual])->save();

        $this->sendTo($bot, $this->message('/admin', chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('🤝 نماینده', $this->said()[0]);
        $this->sendTo($bot, $this->tap(Nav::to('db'), chat: self::AGENT_TELEGRAM_ID));

        $said = $this->said()[0];
        self::assertStringContainsString('فروش ربات شما متوقف است: فروشگاه اصلی فروش را متوقف کرده است.', $said);
        self::assertStringContainsString('فقط پشتیبانی فروشگاه اصلی آن را دوباره باز می‌کند.', $said);
        self::assertStringNotContainsString('وضعیت سیستم', $said);
        self::assertContains(Nav::to('acc'), $this->callbacks(0), 'their account a tap away');
        self::assertContains('https://t.me/amo_shop_bot', array_column(array_merge(...$this->inlineKeyboard(0)), 'url'), 'where traffic is bought');
    }

    public function testAnAgentWhoseRenewalWaitsIsToldItWaitsNotToAskAgain(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $bot = $this->sellingTerms($this->agentBot(), Carbon::parse('2026-10-03 18:00:00'));
        $press = 'برای تمدید، در «💼 حساب نمایندگی» «🔄 درخواست تمدید نمایندگی» را بزنید.';
        $waits = 'درخواست تمدید شما در انتظار بررسی است.';

        $this->sendTo($bot, $this->message('/admin', chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->tap(Nav::to('db'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString($press, $this->said()[0], 'the end near: how to renew it');

        self::assertNotNull($this->service(AgencyActions::class)->requestRenewal($bot->agent ?? self::fail('no agent')));
        $this->sendTo($bot, $this->tap(Nav::to('db'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('ربات شما فروش تازه‌ای ندارد؛ ' . $waits, $this->said()[0], 'GET /account read for the end');
        self::assertStringNotContainsString($press, $this->said()[0]);
    }
}
