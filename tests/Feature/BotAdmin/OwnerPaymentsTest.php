<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Bots\CurrentBot;
use App\Modules\Payments\Deposits\Services\DepositSettings;
use App\Modules\Payments\Models\PaymentCard;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use Tests\BotTestCase;
use Tests\Support\FakeZarinpal;

/**
 * «💳 روش‌های پرداخت» in the bot (Telegram\Admin\Sections\Owner\Payments): the list in checkout order, a method switched,
 * moved and deleted (one with payments refused in the panel's words), a card method added by its driver's described form
 * with its first card, an online gateway whose secret is kept on an edit, a method's cards, and «تایید خودکار واریز» with
 * its key — every write through the panel's own routes; the owner's and an agent's in their own bot, the shop's admins'
 * as theirs.
 */
final class OwnerPaymentsTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->owner();
    }

    public function testAMethodIsSwitchedMovedAndDeletedAndOneWithPaymentsIsKept(): void
    {
        $wallet = $this->walletMethod();
        $card = $this->cardMethod();
        $this->cardPayment($this->topUpOrder($this->customer(), '50000'), $card);
        $spare = $this->cardMethod('کارت دوم');

        $this->send($this->tap(Nav::to('hs'), chat: self::OWNER));
        self::assertContains(Nav::to('pm'), $this->callbacks(0), 'in the shop menu');
        $this->send($this->tap(Nav::to('pm'), chat: self::OWNER));
        self::assertContains('🟢 کارت به کارت (ملت) · ۱ کارت', $this->buttonLabels(0), 'the list\'s buttons are its rows: chip, name, how it settles');
        self::assertContains(Nav::to('pmv', 'show', $card->id), $this->callbacks(0));

        $this->send($this->tap(Nav::to('pmv', 'on', $card->id, 0), chat: self::OWNER));
        self::assertFalse($card->refresh()->enabled, 'switched off through PATCH');
        self::assertSame('⏸ غیرفعال شد', $this->popup());
        self::assertStringContainsString('وضعیت: <b>⏸ غیرفعال</b>', $this->said()[0]);
        self::assertStringContainsString('مدیریت › فروشگاه › روش‌های پرداخت', $this->said()[0]);

        $this->send($this->tap(Nav::to('pmv', 'up', $spare->id), chat: self::OWNER));
        self::assertSame([$wallet->id, $spare->id, $card->id], PaymentMethod::query()->where('driver', '!=', 'outside')->orderBy('sort')->pluck('id')->all());

        $this->send($this->tap(Nav::to('pmv', 'del', $card->id), chat: self::OWNER));
        self::assertStringContainsString('برای حفظ تاریخچه حذف نمی‌شود', (string) $this->popup());

        $this->send($this->tap(Nav::to('pmv', 'show', $wallet->id), chat: self::OWNER));
        self::assertNotContains(Nav::to('pmv', 'del', $wallet->id), $this->callbacks(0), 'the wallet is never deleted');

        $this->send($this->tap(Nav::to('pmv', 'del', $spare->id), chat: self::OWNER));
        self::assertContains(Nav::to('pmv', 'gone', $spare->id), $this->callbacks(0), 'asked first');
        $this->send($this->tap(Nav::to('pmv', 'gone', $spare->id), chat: self::OWNER));
        self::assertNull(PaymentMethod::query()->find($spare->id));
    }

    public function testTheListSaysWhyAMethodIsOfferedToNobodyAndTheDriversSayWhatTheyAreInALine(): void
    {
        $card = $this->cardMethod();
        PaymentCard::query()->where('payment_method_id', $card->id)->update(['enabled' => false]);

        $this->send($this->tap(Nav::to('pm'), chat: self::OWNER));
        self::assertContains('⚠️ کارت به کارت (ملت) · ۱ کارت', $this->buttonLabels(0), 'on, yet offered to nobody: its chip says so');
        self::assertStringContainsString('⚠️ کارت به کارت (ملت): همه کارت‌ها غیرفعال‌اند', $this->said()[0], 'and why, over the list');
        self::assertContains('⚡️ تایید خودکار واریز · ⏸ خاموش', $this->buttonLabels(0), 'the approval\'s state on its button');
        self::assertContains(Nav::to('pm'), $this->callbacks(0), '«🔄 تازه‌سازی»');

        $this->send($this->tap(Nav::to('pma'), chat: self::OWNER));
        self::assertStringContainsString('<b>زرین‌پال</b>: پرداخت آنلاین با هر کارت بانکی در درگاه زرین‌پال', $this->said()[0], 'each driver in one line: what it is, not how to set it up');
        self::assertStringNotContainsString('مرچنت کد', $this->said()[0]);

        $first = PaymentCard::query()->where('payment_method_id', $card->id)->value('id');
        $this->send($this->tap(Nav::to('pmc', 'show', $card->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmcv', 'show', $first), chat: self::OWNER));
        $copies = array_column(array_merge(...$this->inlineKeyboard(0)), 'copy_text');
        self::assertContains(['text' => '6037997700001119'], $copies, 'the card\'s number copied at a tap');
    }

    public function testACardShownToAPaymentIsKeptAndTheCardsRefreshTheirOwnMethod(): void
    {
        $card = $this->cardMethod();
        $this->cardPayment($this->topUpOrder($this->customer(), '50000'), $card);
        $paid = PaymentCard::query()->where('payment_method_id', $card->id)->sole();

        $this->send($this->tap(Nav::to('pmc', 'show', $card->id), chat: self::OWNER));
        self::assertContains(Nav::to('pmc', 'show', $card->id), $this->callbacks(0), '«🔄 تازه‌سازی» names its method: a window kept from before refreshes its own cards');

        $this->send($this->tap(Nav::to('pmcv', 'show', $paid->id), chat: self::OWNER));
        self::assertNotContains(Nav::to('pmcv', 'del', $paid->id), $this->callbacks(0), 'no delete offered');
        $this->send($this->tap(Nav::to('pmcv', 'del', $paid->id), chat: self::OWNER));
        self::assertStringContainsString('با این کارت پرداخت شده', (string) $this->popup(), 'an old or forged delete: said, not asked');
        self::assertStringNotContainsString('❓', implode("\n", $this->said()), 'no question claiming no payment was shown it');
        self::assertTrue(PaymentCard::query()->whereKey($paid->id)->exists());
    }

    public function testACardMethodIsAddedByItsDriversFormWithItsFirstCard(): void
    {
        $this->send($this->tap(Nav::to('pma'), chat: self::OWNER));
        self::assertContains(Nav::to('pmf', 'new', 'manual'), $this->callbacks(0));
        self::assertNotContains(Nav::to('pmf', 'new', 'wallet'), $this->callbacks(0), 'the wallet is built in');

        $this->send($this->tap(Nav::to('pmf', 'new', 'manual'), chat: self::OWNER));
        self::assertStringContainsString('نام روش', $this->said()[0]);
        $this->send($this->message('کارت به کارت (ملی)', chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 's', 1), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 's', 2), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 's', 3), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 's', 4), chat: self::OWNER));
        self::assertStringContainsString('شماره کارت', $this->said()[0]);
        $this->send($this->message('۶۰۳۷-۹۹۷۷-۰۰۰۰-۱۱۱۹', chat: self::OWNER));
        $this->send($this->message('AmoBot', chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 's', 7), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 's', 8), chat: self::OWNER));
        self::assertStringContainsString('6037 9977 0000 1119', $this->said()[0], 'the walk ends on the card');

        $this->send($this->tap(Nav::to('pmf', 'save'), chat: self::OWNER));
        $method = PaymentMethod::query()->where('label', 'کارت به کارت (ملی)')->sole();
        self::assertSame('manual', $method->driver);
        self::assertSame(['6037997700001119'], PaymentCard::query()->where('payment_method_id', $method->id)->pluck('number')->all());

        // A second card from the method's cards.
        $this->send($this->tap(Nav::to('pmc', 'show', $method->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmcn', 'new', $method->id), chat: self::OWNER));
        $this->send($this->message('6037991100002223', chat: self::OWNER));
        $this->send($this->message('Ali', chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmcn', 's', 2), chat: self::OWNER));
        $this->send($this->message('۳۰۰ هزار تومان', chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmcn', 'save'), chat: self::OWNER));
        $second = PaymentCard::query()->where('number', '6037991100002223')->sole();
        self::assertSame(['Ali', '300000'], [$second->holder, (string) (int) $second->daily_cap]);

        $this->send($this->tap(Nav::to('pmcv', 'on', $second->id, 0), chat: self::OWNER));
        self::assertFalse($second->refresh()->enabled);
        self::assertSame(2, PaymentCard::query()->where('payment_method_id', $method->id)->count(), 'the other card kept as it was');
    }

    public function testAnOnlineGatewaysSecretIsNeverShownAndKeptOnAnEdit(): void
    {
        $this->send($this->tap(Nav::to('pmf', 'new', 'zarinpal'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 's', 0), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 's', 1), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 's', 2), chat: self::OWNER));
        $this->send($this->message(FakeZarinpal::MERCHANT, chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 's', 4), chat: self::OWNER));
        self::assertStringNotContainsString(FakeZarinpal::MERCHANT, implode("\n", $this->said()));
        $this->send($this->tap(Nav::to('pmf', 'save'), chat: self::OWNER));
        $method = PaymentMethod::query()->where('driver', 'zarinpal')->sole();

        $this->send($this->tap(Nav::to('pmf', 'edit', $method->id), chat: self::OWNER));
        self::assertStringContainsString('ذخیره شده', $this->said()[0]);
        self::assertContains(Nav::to('pmf', 't', 4, 1), $this->callbacks(0), 'the switch\'s button carries the value it sets');
        $this->send($this->tap(Nav::to('pmf', 't', 4, 1), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 't', 4, 1), chat: self::OWNER));
        $this->send($this->tap(Nav::to('pmf', 'save'), chat: self::OWNER));
        self::assertTrue($method->refresh()->config['sandbox'] ?? false, 'the switch saved');
        self::assertStringContainsString('حالت آزمایشی', $this->said()[0], 'its summary on its page');
    }

    public function testAutomaticDepositApprovalShowsItsKeyAndANewOneIsAskedFirst(): void
    {
        $this->send($this->tap(Nav::to('pmd'), chat: self::OWNER));
        self::assertContains(Nav::to('pmd', 'on', 1), $this->callbacks(0));
        $this->send($this->tap(Nav::to('pmd', 'on', 1), chat: self::OWNER));
        $key = $this->service(DepositSettings::class)->key();
        self::assertStringContainsString('<tg-spoiler><code>' . $key . '</code></tg-spoiler>', $this->said()[0], 'the key behind a spoiler, copied whole');

        self::assertStringContainsString('تایید خودکار واریزها: <b>🟢 روشن</b>', $this->said()[0], 'how it stands first, in its card');
        $copies = array_column(array_merge(...$this->inlineKeyboard($this->windowCall())), 'copy_text');
        self::assertContains(['text' => $key], $copies, 'the key copied by its button too');
        self::assertContains(Nav::to('pmd'), $this->callbacks($this->windowCall()), '«🔄 تازه‌سازی»: whether the test message came');

        $this->send($this->tap(Nav::to('pmd', 'key'), chat: self::OWNER));
        self::assertContains(Nav::to('pmd', 'rotate'), $this->callbacks(0), 'asked first');
        self::assertSame('danger', $this->inlineKeyboard(0)[0][1]['style'] ?? null, 'red: the key in ForApp stops working');
        $this->send($this->tap(Nav::to('pmd', 'rotate'), chat: self::OWNER));
        self::assertNotSame($key, $this->service(DepositSettings::class)->key());
    }

    public function testAnAgentManagesTheirOwnMethodsAndTheMainShopsAdminTheMainOnes(): void
    {
        $bot = $this->agentBot();
        $agentMethod = CurrentBot::run($bot, fn(): PaymentMethod => $this->cardMethod('کارت نماینده'));

        $this->sendTo($bot, $this->message('/admin', chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('🤝 نماینده', $this->said()[0]);
        $this->sendTo($bot, $this->tap(Nav::to('pmv', 'on', $agentMethod->id, 0), chat: self::AGENT_TELEGRAM_ID));
        self::assertFalse(CurrentBot::run($bot, static fn(): bool => $agentMethod->refresh()->enabled), 'through the agent panel\'s route');
        $this->sendTo($bot, $this->tap(Nav::to('sv'), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringNotContainsString('🖥 سرورها', $this->said()[0], 'the servers are the owner\'s');

        // The main shop's admin: the main shop's methods, as the owner manages them.
        $card = $this->cardMethod();
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);
        $this->send($this->tap(Nav::to('hs'), chat: 7100));
        self::assertContains(Nav::to('pm'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('pmv', 'on', $card->id, 0), chat: 7100));
        self::assertFalse($card->refresh()->enabled, 'through the owner panel\'s route');
        self::assertFalse(CurrentBot::run($bot, static fn(): bool => $agentMethod->refresh()->enabled), 'the agent\'s as they left it');
    }
}
