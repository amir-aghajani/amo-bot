<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Bots\Enums\SellingSuspension;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use Tests\BotTestCase;

/**
 * «💼 حساب نمایندگی» in an agent's bot (Telegram\Admin\Sections\Sales\AgentAccountScreen): the agent panel's account page
 * — their bot, traffic, level, wallet and credit, terms of selling, the traffic ledger a page at a time, and the main bot
 * where traffic is bought — through the agent's own routes; nobody else's seat opens it.
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
        self::assertStringContainsString('فروش: متوقف: فروشگاه اصلی فروش را متوقف کرده است', $said, 'the terms of selling');
        self::assertStringContainsString('پایان نمایندگی: بدون پایان', $said);
        self::assertContains(Nav::to('acc', 'l', 1), $this->callbacks(0));
        self::assertContains('https://t.me/amo_shop_bot', array_column(array_merge(...$this->inlineKeyboard(0)), 'url'), 'traffic is bought in the main bot');

        $this->sendTo($bot, $this->tap(Nav::to('acc', 'l', 1), chat: self::AGENT_TELEGRAM_ID));
        $ledger = $this->said()[0];
        self::assertStringContainsString('📊 گردش حجم', $ledger);
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
