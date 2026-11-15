<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\SellingSuspension;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Telegram\Admin\SeatKind;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use App\Support\Persian;
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
        self::assertStringContainsString('نیازمند توجه</b> · ۱ مورد منتظر اقدام شماست', $said);
        self::assertStringContainsString('تیکت‌های در انتظار پاسخ: <b>۱</b>', $said);
        self::assertStringContainsString('وضعیت سیستم', $said, 'the owner\'s system card');
        self::assertContains(Nav::to('db', 'q', 'open_tickets'), $this->callbacks(0), 'the count leads to its list on its tab');
        self::assertContains(Nav::to('db', 'r', 7), $this->callbacks(0));

        // A search and one customer's narrowing left on the list would hide what the count counted.
        $sara = $this->customer(['telegram_id' => 9001, 'first_name' => 'Sara']);
        $this->send($this->tap(Nav::to('tk', 'user', $sara->id), chat: 7001));
        $this->send($this->tap(Nav::to('tk', 'search'), chat: 7001));
        $this->send($this->message('چیزی که نیست', chat: 7001));

        $this->send($this->tap(Nav::to('db', 'q', 'open_tickets'), chat: 7001));
        self::assertStringContainsString('● ⏳ در انتظار پاسخ', (string) json_encode($this->inlineKeyboard(0), JSON_UNESCAPED_UNICODE), 'on the count\'s tab');
        self::assertStringNotContainsString('🔎', $this->said()[0], 'no search left on it');
        self::assertContains(Nav::to('tkv', 'show', $ticket->id), $this->callbacks(0), 'every one the count counted');

        $this->send($this->tap(Nav::to('db', 'r', 7), chat: 7001));
        self::assertStringContainsString('۷ روز گذشته', $this->said()[0]);
        self::assertContains(Nav::to('db', 'r', 30), $this->callbacks(0));
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
        $said = $this->said()[0];
        self::assertStringContainsString('آخرین سفارش‌ها', $said);
        self::assertStringContainsString('<b>#' . Persian::digits($order->id) . '</b>', $said);
        self::assertStringContainsString('شارژ کیف پول · ۷۰٬۰۰۰ تومان', $said);
        self::assertStringContainsString('در انتظار پرداخت', $said);

        $this->send($this->tap(Nav::to('dbr', 'show', 'u'), chat: 7001));
        self::assertStringContainsString('مشتریان جدید', $this->said()[0]);
        self::assertStringContainsString('Sara', $this->said()[0]);
    }

    public function testAShopsAdminSeesTheDailyWorkWithoutTheSystem(): void
    {
        $admin = $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->message('/admin', chat: 7100));
        self::assertContains(Nav::to('db'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('db'), chat: 7100));

        self::assertStringContainsString('📊 داشبورد', $this->said()[0]);
        self::assertStringContainsString('همه‌چیز مرتب است', $this->said()[0]);
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

    /** The account 7001 linked as the owner. */
    private function owner(): User
    {
        $owner = User::query()->where('telegram_id', 7001)->first() ?? $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        $this->linkOwner(7001);

        return $owner;
    }
}
