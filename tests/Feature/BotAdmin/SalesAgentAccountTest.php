<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Agency\Enums\AgencyRequestKind;
use App\Modules\Agency\Models\AgencyRequest;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Bots\Enums\SellingSuspension;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Users\Enums\UserRole;
use App\Support\Persian;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * «💼 حساب نمایندگی» in an agent's bot (Telegram\Admin\Sections\Sales\AgentAccountScreen): the agent panel's account page
 * — their bot, traffic, level, wallet and credit, terms of selling with their latest renewal request, the traffic ledger
 * a page at a time, and the main bot where traffic is bought — through the agent's own routes, and «🔄 درخواست تمدید
 * نمایندگی» while their agency has an end; nobody else's seat opens it.
 */
final class SalesAgentAccountTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testAnAgentReadsTheirAccountAndTheirTrafficLedger(): void
    {
        $bot = $this->agentBot();
        $bot->forceFill(['selling_suspended_at' => now(), 'suspension_reason' => SellingSuspension::Manual])->save();

        $this->sendTo($bot, $this->tap(Nav::to('acc'), chat: self::AGENT_TELEGRAM_ID));
        $said = $this->said()[0];
        self::assertStringContainsString('💼 حساب نمایندگی', $said);
        self::assertStringContainsString('حجم باقی‌مانده: <b>۱۰۰ گیگابایت</b>', $said);
        self::assertStringContainsString('قیمت هر گیگابایت', $said);
        self::assertStringContainsString('کیف پول شما در فروشگاه', $said);
        self::assertStringContainsString('بدون اعتبار خرید', $said);
        self::assertStringContainsString("\u{2068}@agent_shop_bot\u{2069} · 🟢 فعال", $said, 'their bot and how it stands');
        self::assertStringContainsString('فروش ربات شما متوقف است', $said, 'why it sells nothing');
        self::assertStringContainsString('فروش: <b>متوقف: فروشگاه اصلی فروش را متوقف کرده است</b>', $said, 'the terms of selling');
        self::assertStringContainsString('پایان نمایندگی: <b>بدون پایان</b>', $said);

        self::assertContains(Nav::to('acc', 'l', 1), $this->callbacks(0));
        self::assertContains('https://t.me/amo_shop_bot', array_column(array_merge(...$this->inlineKeyboard(0)), 'url'), 'traffic is bought in the main bot');

        $this->sendTo($bot, $this->tap(Nav::to('acc', 'l', 1), chat: self::AGENT_TELEGRAM_ID));
        $ledger = $this->said()[0];
        self::assertStringContainsString('📒 گردش حجم', $ledger);
        self::assertStringContainsString('➕ ۱۰۰ گیگابایت', $ledger, 'the traffic the bot was given');
        self::assertStringContainsString('مانده ۱۰۰ گیگابایت', $ledger);
        self::assertContains(Nav::to('acc'), $this->callbacks(0), 'back to the account');
    }

    public function testABotWithoutTrafficSaysWhereToBuyIt(): void
    {
        $bot = $this->agentBot(traffic: 0);

        $this->sendTo($bot, $this->tap(Nav::to('acc'), chat: self::AGENT_TELEGRAM_ID));
        $said = $this->said()[0];
        self::assertStringContainsString('حجم ربات تمام شده است', $said);
        self::assertSame(1, substr_count($said, 'خرید حجم: ربات اصلی'), 'where traffic is bought, said once: in the notice');

        $this->sendTo($bot, $this->tap(Nav::to('acc', 'l', 1), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('هنوز گردشی ندارد', $this->said()[0]);
    }

    public function testTheirCreditSaysWhetherItIsInUseAtItsEndOrPastIt(): void
    {
        $bot = $this->agentBot();
        $agent = $bot->agent ?? self::fail('no agent');
        $agent->forceFill(['credit_limit' => '100000'])->save();

        foreach ([
            ['0', 'اعتبار خرید: <b>۱۰۰٬۰۰۰ تومان</b>'],
            ['-50000', 'اعتبار خرید: <b>۱۰۰٬۰۰۰ تومان (در حال استفاده)</b>'],
            ['-100000', 'اعتبار خرید: <b>۱۰۰٬۰۰۰ تومان (تمام شده)</b>'],
            // Support's debit by hand may take them past it.
            ['-150000', 'اعتبار خرید: <b>۱۰۰٬۰۰۰ تومان (تمام شده؛ بدهی از اعتبار گذشته است)</b>'],
        ] as [$balance, $credit]) {
            $this->wallet($agent, $balance);
            $this->sendTo($bot, $this->tap(Nav::to('acc'), chat: self::AGENT_TELEGRAM_ID));
            self::assertStringContainsString($credit, $this->said()[0], $balance);
        }
    }

    public function testAnAgentAsksForTheirAgencysEndToBeMovedOnOnce(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $this->reportGroup();
        $bot = $this->agentBot();

        $this->sendTo($bot, $this->tap(Nav::to('acc'), chat: self::AGENT_TELEGRAM_ID));
        self::assertNotContains(Nav::to('acc', 'rn'), $this->callbacks(0), 'no end, nothing to renew');

        $this->sellingTerms($bot, Carbon::parse('2026-10-11 18:00:00'));
        $this->sendTo($bot, $this->tap(Nav::to('acc'), chat: self::AGENT_TELEGRAM_ID));
        self::assertContains(Nav::to('acc', 'rn'), $this->callbacks(0));
        self::assertStringNotContainsString('درخواست تمدید:', $this->said()[0]);

        $this->sendTo($bot, $this->tap(Nav::to('acc', 'rn'), chat: self::AGENT_TELEGRAM_ID));
        self::assertSame('✅ درخواست تمدید ثبت شد؛ نتیجه در ربات اصلی به شما اعلام می‌شود', $this->popup());
        $request = AgencyRequest::query()->sole();
        self::assertSame([AgencyRequestKind::Renewal, $bot->user_id], [$request->kind, $request->user_id]);
        self::assertStringContainsString("پایان نمایندگی: <b>۱۹ مهر ۱۴۰۵ (۱۱ روز مانده)</b>\nدرخواست تمدید: <b>🟡 در انتظار بررسی · از ۹ مهر ۱۴۰۵</b>", $this->lastSaid(), 'right under the end it would move on');
        self::assertNotContains(Nav::to('acc', 'rn'), $this->offered(), 'one waits');
        self::assertSame("agency:{$request->id}", ReportMessage::query()->where('topic', 'agency')->sole()->ref, "the main group's report");

        // The window from before, tapped again: refused, and the account as it stands.
        $this->sendTo($bot, $this->tap(Nav::to('acc', 'rn'), chat: self::AGENT_TELEGRAM_ID));
        self::assertSame(AgencyActions::RENEWAL_WAITING, $this->popup());
        self::assertSame(1, AgencyRequest::query()->count());
        self::assertStringContainsString('🟡 در انتظار بررسی', $this->lastSaid());
        self::assertNotContains(Nav::to('acc', 'rn'), $this->offered());
    }

    public function testTheirLastRenewalSaysHowTheOwnerDecidedIt(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $bot = $this->sellingTerms($this->agentBot(), Carbon::parse('2026-10-11 18:00:00'));
        $agent = $bot->agent ?? self::fail('no agent');
        $actions = $this->service(AgencyActions::class);

        $actions->reject($actions->requestRenewal($agent) ?? self::fail('not filed'), $this->panelActor(), 'اول <بدهی> را صاف کنید');
        $this->sendTo($bot, $this->tap(Nav::to('acc'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString("درخواست تمدید: <b>🔴 رد شد · ۹ مهر ۱۴۰۵</b>\nدلیل رد: <b>اول &lt;بدهی&gt; را صاف کنید</b>", $this->said()[0], 'why, as text');
        self::assertContains(Nav::to('acc', 'rn'), $this->callbacks(0), 'they may ask again');

        // The shop read again, as the bot's process reads it (each webhook request; bot:poll every few seconds).
        $actions->renew($actions->requestRenewal($agent->refresh()) ?? self::fail('not filed'), $this->panelActor(), Carbon::parse('2026-12-01 10:00:00'));
        $this->sendTo($bot->refresh(), $this->tap(Nav::to('acc'), chat: self::AGENT_TELEGRAM_ID));
        $new = Persian::date(Carbon::parse('2026-12-01 10:00:00'));
        self::assertStringContainsString("پایان نمایندگی: <b>{$new} (۶۱ روز مانده)</b>\nدرخواست تمدید: <b>✅ تایید شد · ۹ مهر ۱۴۰۵</b>\nپایان تازه: <b>{$new}</b>", $this->said()[0]);
        self::assertContains(Nav::to('acc', 'rn'), $this->callbacks(0), 'the new end may be moved on in its turn');

        $actions->renew($actions->requestRenewal($agent->refresh()) ?? self::fail('not filed'), $this->panelActor(), null);
        $this->sendTo($bot->refresh(), $this->tap(Nav::to('acc'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('پایان تازه: <b>بدون پایان</b>', $this->said()[0], 'none at all');
        self::assertStringContainsString('پایان نمایندگی: <b>بدون پایان</b>', $this->said()[0]);
        self::assertNotContains(Nav::to('acc', 'rn'), $this->callbacks(0), 'nothing left to renew');
    }

    public function testWhileTheirRenewalWaitsTheEndsNoticeSaysSoInsteadOfTheButton(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $bot = $this->sellingTerms($this->agentBot(), Carbon::parse('2026-10-03 18:00:00'));
        $agent = $bot->agent ?? self::fail('no agent');
        $press = 'برای تمدید، در «💼 حساب نمایندگی» «🔄 درخواست تمدید نمایندگی» را بزنید.';
        $waits = 'درخواست تمدید شما در انتظار بررسی است.';

        $this->sendTo($bot, $this->tap(Nav::to('acc'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('نمایندگی شما ۱۱ مهر ۱۴۰۵ به پایان می‌رسد', $this->said()[0], 'the end near');
        self::assertStringContainsString($press, $this->said()[0]);
        self::assertStringNotContainsString($waits, $this->said()[0]);

        self::assertNotNull($this->service(AgencyActions::class)->requestRenewal($agent));
        $this->sendTo($bot, $this->tap(Nav::to('acc'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('ربات شما فروش تازه‌ای ندارد؛ ' . $waits, $this->said()[0], 'beside «🟡 در انتظار بررسی», no button promised');
        self::assertStringNotContainsString($press, $this->said()[0]);
        self::assertNotContains(Nav::to('acc', 'rn'), $this->callbacks(0));

        // The end passed: selling suspended for it, the request still waiting.
        Carbon::setTestNow('2026-10-04 12:00:00');
        $bot->forceFill(['selling_suspended_at' => now(), 'suspension_reason' => SellingSuspension::Expired])->save();
        $this->sendTo($bot->refresh(), $this->tap(Nav::to('acc'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('تاریخ نمایندگی گذشته است.', $this->said()[0]);
        self::assertStringContainsString(' تازه انجام نمی‌شود. ' . $waits, $this->said()[0]);
        self::assertStringNotContainsString($press, $this->said()[0]);
    }

    public function testNoOtherSeatOpensIt(): void
    {
        $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        $this->linkOwner(7001);
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        foreach ([7001, 7100] as $chat) {
            $this->send($this->tap(Nav::to('acc'), chat: $chat));
            self::assertStringNotContainsString('حساب نمایندگی', $this->said()[0]);
            self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'the home instead');
        }
    }
}
