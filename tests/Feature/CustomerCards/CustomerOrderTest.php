<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerCards;

use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\ManualPayment;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\ManualOrders;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Customers\CustomerCards;
use App\Modules\Telegram\Reports\GroupButtons;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «➕ سرویس دستی» on a customer's card in the report group: a plan the shop offers (paged), one of its servers that can
 * deliver it, how it is paid — free, from the wallet or outside the shop at the plan's price, or an amount typed in reply
 * —, the summary asked again, then the order made as the panel's «افزودن سفارش» makes it: delivered and told to the
 * customer, or waiting for its retry. The same «بله» pressed twice makes one order; a refusal is a popup. Never the
 * admin's own account nor an agent's, and only for the group's bot admins.
 */
final class CustomerOrderTest extends BotTestCase
{
    /** A card of the bot's in the group, under which the buttons are pressed. */
    private const CARD = 5252;

    /** @var array<string, int> */
    private array $threads;

    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->withoutQr();
        $this->threads = $this->reportGroup();
        $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);
        $this->server = $this->sellingServer();
        $this->plan = $this->plan([], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
    }

    public function testThePlansOfferedThenTheirServersThenTheWaysToPay(): void
    {
        $id = $this->ali->id;
        $elsewhere = $this->sellingServer('هلند');
        $this->plan(['name' => 'خاموش', 'is_active' => false], $this->server);
        $this->plan(['name' => 'بی‌سرور']);

        $this->send($this->press("uo:o:{$id}"));
        $card = $this->edited();
        self::assertStringStartsWith("⚙️ <b>مدیریت کاربر › سرویس دستی</b> · \u{200E}#{$id}", $this->params($card)['text']);
        self::assertStringEndsWith('👇 پلن سرویس را انتخاب کنید؛ بعد سرور و روش پرداخت. سرویس بلافاصله تحویل و به مشتری خبر داده می‌شود.', $this->params($card)['text']);
        self::assertSame([['یک‌ماهه · ۱۲۰٬۰۰۰ تومان'], ['⬅️ بازگشت']], $this->labels($card), 'only the plans the shop offers now');
        self::assertSame(["uo:s:{$id}:{$this->plan->id}", "um:o:{$id}"], $this->callbacks($card));

        $this->planEntry($this->plan, $elsewhere);
        $this->send($this->press("uo:s:{$id}:{$this->plan->id}"));
        $card = $this->edited();
        self::assertStringContainsString("\n\n📦 پلن: یک‌ماهه · ۳۰ گیگابایت · ۳۰ روز", $this->params($card)['text']);
        self::assertStringEndsWith('👇 سرور را انتخاب کنید:', $this->params($card)['text']);
        self::assertSame([['هلند', 'آلمان'], ['⬅️ بازگشت']], $this->labels($card), 'a button a server, the first on the right');
        self::assertSame(["uo:p:{$id}:{$this->plan->id}:{$elsewhere->id}", "uo:p:{$id}:{$this->plan->id}:{$this->server->id}", "uo:o:{$id}:1"], $this->callbacks($card));

        $this->send($this->press("uo:p:{$id}:{$this->plan->id}:{$this->server->id}"));
        $card = $this->edited();
        self::assertStringContainsString("\n📍 لوکیشن: آلمان\n👛 موجودی کیف پول مشتری: ۰ تومان\n👇 چطور پرداخت شود؟", $this->params($card)['text']);
        self::assertSame([
            ['🎁 رایگان'],
            ['👛 از کیف پول · ۱۲۰٬۰۰۰ تومان'],
            ['💵 پرداخت بیرون از فروشگاه · ۱۲۰٬۰۰۰ تومان'],
            ['✍️ مبلغ دیگر · بیرون', '✍️ مبلغ دیگر · کیف پول'],
            ['⬅️ بازگشت'],
        ], $this->labels($card));
        $at = "{$id}:{$this->plan->id}:{$this->server->id}";
        self::assertSame(["uo:c:{$at}:1:0", "uo:c:{$at}:2:120000", "uo:c:{$at}:3:120000", "uo:a:{$at}:3", "uo:a:{$at}:2", "uo:s:{$id}:{$this->plan->id}"], $this->callbacks($card));

        $this->walletMethod()->forceFill(['enabled' => false])->save();
        $this->send($this->press("uo:p:{$at}"));
        self::assertSame([['🎁 رایگان'], ['💵 پرداخت بیرون از فروشگاه · ۱۲۰٬۰۰۰ تومان'], ['✍️ مبلغ دیگر · بیرون'], ['⬅️ بازگشت']], $this->labels($this->edited()), 'no wallet while the shop\'s is off');
        self::assertFalse(Order::query()->exists(), 'nothing made by picking');
    }

    public function testManyPlansArePagedAndTheServersGoBackToTheirPage(): void
    {
        $id = $this->ali->id;
        $plans = [$this->plan];
        foreach (range(2, 12) as $n) {
            $plans[] = $this->plan(['name' => "پلن {$n}"], $this->server);
        }

        $this->send($this->press("uo:o:{$id}"));
        $card = $this->edited();
        self::assertStringEndsWith("\nصفحه ۱ از ۲", $this->params($card)['text']);
        self::assertSame([['⬅️ بعدی'], ['⬅️ بازگشت']], array_slice($this->labels($card), -2));
        self::assertCount(10, array_merge(...array_slice($this->labels($card), 0, 5)));
        self::assertSame("uo:o:{$id}:2", $this->callbacks($card)[10]);

        $this->send($this->press("uo:o:{$id}:2"));
        $card = $this->edited();
        self::assertSame([['پلن 12 · ۱۲۰٬۰۰۰ تومان', 'پلن 11 · ۱۲۰٬۰۰۰ تومان'], ['➡️ قبلی'], ['⬅️ بازگشت']], $this->labels($card));
        self::assertStringEndsWith("\nصفحه ۲ از ۲", $this->params($card)['text']);

        $this->send($this->press("uo:s:{$id}:{$plans[11]->id}"));
        self::assertSame("uo:o:{$id}:2", array_slice($this->callbacks($this->edited()), -1)[0], 'back to the page it is on');
    }

    public function testEachWayToPayIsAskedAgainWithItsAmountBeforeAnythingIsMade(): void
    {
        $id = $this->ali->id;
        $at = "{$id}:{$this->plan->id}:{$this->server->id}";
        $this->wallet($this->ali, '50000');

        $this->send($this->press("uo:c:{$at}:1:0"));
        $card = $this->edited();
        self::assertStringEndsWith(implode("\n", [
            '📦 پلن: یک‌ماهه · ۳۰ گیگابایت · ۳۰ روز · ۱ کاربر · ۱۲۰٬۰۰۰ تومان',
            '📍 لوکیشن: آلمان',
            '💳 پرداخت: رایگان؛ هدیه است و خرید مشتری حساب نمی‌شود',
            '',
            '❓ <b>ساخته شود؟</b> مشتری بعد از تحویل خبردار می‌شود.',
        ]), $this->params($card)['text']);
        self::assertSame([['⬅️ انصراف', '✔️ بله، ساخته شود']], $this->labels($card), '«انصراف», then «بله» on the right');
        self::assertSame('success', $this->inlineKeyboard($card)[0][1]['style']);
        self::assertSame("um:o:{$id}", $this->callbacks($card)[0]);
        self::assertMatchesRegularExpression("/^uo:y:{$at}:1:0:[0-9a-z]{6}$/", $this->yes());

        $this->send($this->press("uo:c:{$at}:2:120000"));
        self::assertStringContainsString("\n💳 پرداخت: از کیف پول مشتری · ۱۲۰٬۰۰۰ تومان\n👛 موجودی کیف پول مشتری: ۵۰٬۰۰۰ تومان\n", $this->params($this->edited())['text']);
        self::assertMatchesRegularExpression("/^uo:y:{$at}:2:120000:[0-9a-z]{6}$/", $this->yes());

        $this->send($this->press("uo:c:{$at}:3:120000"));
        self::assertStringContainsString("\n💳 پرداخت: پرداخت خارج از فروشگاه · ۱۲۰٬۰۰۰ تومان؛ در درآمد فروشگاه حساب می‌شود\n", $this->params($this->edited())['text']);
        $first = $this->yes();
        $this->send($this->press("uo:c:{$at}:3:120000"));
        self::assertNotSame($first, $this->yes(), 'a nonce drawn with each summary');

        self::assertFalse(Order::query()->exists(), 'asked, not made');
        self::assertSame('50000.00', $this->ali->balance());
    }

    public function testAnAmountTypedInReplyLeadsToTheSummary(): void
    {
        $id = $this->ali->id;
        $at = "{$id}:{$this->plan->id}:{$this->server->id}";

        $this->send($this->press("uo:a:{$at}:3"));
        self::assertSame(['sendMessage', 'answerCallbackQuery'], $this->calls());
        $question = $this->params(0)['text'];
        self::assertSame("✍️ چه مبلغی بیرون از فروشگاه گرفته‌اید؟ مبلغ را به تومان بنویسید؛ در درآمد فروشگاه حساب می‌شود. \u{200E}@boss", $question);
        self::assertSame(self::CARD, json_decode($this->params(0)['reply_parameters'], true)['message_id'], 'under the card');
        self::assertSame(CustomerCards::WRITE_REPLY, $this->popup());
        $prompt = $this->prompt();
        self::assertSame("ask:uo:a:{$id}:" . self::CARD . ":{$this->plan->id}:{$this->server->id}:3", $prompt->ref);

        $this->send($this->groupReply($question, 'صد و پنجاه', $this->threads['users'], (int) $prompt->message_id));
        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame(sprintf(ManualOrders::NOT_AN_AMOUNT, Money::format(ManualOrders::AMOUNT_MAX)), $this->params(0)['text'], 'the question kept');

        $this->send($this->groupReply($question, '۱۵۰ هزار تومان', $this->threads['users'], (int) $prompt->message_id));
        self::assertSame(['editMessageText', 'deleteMessage', 'sendMessage'], $this->calls());
        self::assertSame((string) self::CARD, $this->params(0)['message_id'], 'the card rewritten');
        self::assertStringContainsString("\n💳 پرداخت: پرداخت خارج از فروشگاه · ۱۵۰٬۰۰۰ تومان؛", $this->params(0)['text']);
        self::assertSame((string) $prompt->message_id, $this->params(1)['message_id'], 'the question deleted');
        self::assertSame('✅ مبلغ ۱۵۰٬۰۰۰ تومان شد؛ برای ساختن سرویس، روی کارت «✔️ بله، ساخته شود» را بزنید.', $this->params(2)['text']);
        $yes = $this->callbacks(0)[1];
        self::assertMatchesRegularExpression("/^uo:y:{$at}:3:150000:[0-9a-z]{6}$/", $yes);

        $this->send($this->press($yes));
        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, '150000.00', ManualPayment::Outside], [$order->status, $order->amount, $order->manualPayment()]);
    }

    public function testAFreeOrderIsDeliveredTheCustomerToldAndTheSameYesMakesItOnce(): void
    {
        $id = $this->ali->id;
        $this->send($this->press("uo:c:{$id}:{$this->plan->id}:{$this->server->id}:1:0"));
        $yes = $this->yes();

        $this->send($this->press($yes));

        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, '0.00', ManualPayment::Free, '@boss'], [$order->status, $order->amount, $order->manualPayment(), $order->created_by]);
        self::assertCount(1, FakeProvider::$created, 'the panel made the client');
        self::assertCount(1, $this->telegram()->sentTo(self::TELEGRAM_ID), 'the customer is told');
        $card = $this->edited();
        self::assertStringEndsWith(implode("\n", [
            "✅ <b>ساخته و تحویل شد</b> · سفارش \u{200E}#{$order->id}",
            '📦 پلن: یک‌ماهه',
            '📍 لوکیشن: آلمان',
            '💳 پرداخت: رایگان',
            '🔖 سرویس: <code>ali_1</code>',
            'به مشتری خبر داده شد.',
        ]), $this->params($card)['text']);
        self::assertSame([['⬅️ بازگشت', '🛍 سرویس‌ها']], $this->labels($card));
        self::assertSame(["um:o:{$id}", "us:o:{$id}"], $this->callbacks($card));
        self::assertSame('✅ ساخته و تحویل شد', $this->popup());

        // Pressed again — by another admin, or twice in a hurry: the same order, as it stands.
        $this->send($this->press($yes));
        self::assertSame(1, Order::query()->count());
        self::assertCount(1, FakeProvider::$created, 'delivered once');
        self::assertSame([], $this->telegram()->sentTo(self::TELEGRAM_ID), 'told once');
        self::assertStringContainsString("سفارش \u{200E}#{$order->id}", $this->params($this->edited())['text']);
    }

    public function testAShortWalletIsRefusedInAPopupAndAFullOneIsCharged(): void
    {
        $id = $this->ali->id;
        $this->wallet($this->ali, '10000');
        $this->send($this->press("uo:c:{$id}:{$this->plan->id}:{$this->server->id}:2:120000"));
        $yes = $this->yes();

        $this->send($this->press($yes));
        self::assertSame(sprintf(ManualOrders::WALLET_SHORT, Money::format('10000'), Money::format('110000')), $this->popup());
        self::assertSame('true', $this->params(array_search('answerCallbackQuery', $this->calls(), true))['show_alert']);
        self::assertNotContains('editMessageText', $this->calls(), 'the summary stays, for another try');
        self::assertFalse(Order::query()->exists());
        self::assertSame('10000.00', $this->ali->balance());

        $this->wallet($this->ali, '200000');
        $this->send($this->press($yes));
        self::assertSame(OrderStatus::Fulfilled, Order::query()->sole()->status);
        self::assertSame('80000.00', $this->ali->balance());
    }

    public function testAFailedDeliveryWaitsForItsRetry(): void
    {
        FakeProvider::$refusing[$this->server->id] = ['createClient'];
        $this->send($this->press("uo:c:{$this->ali->id}:{$this->plan->id}:{$this->server->id}:3:120000"));

        $this->send($this->press($this->yes()));

        $order = Order::query()->sole();
        self::assertSame(OrderStatus::Failed, $order->status);
        $text = $this->params($this->edited())['text'];
        self::assertStringContainsString("⚠️ <b>سفارش \u{200E}#{$order->id} پرداخت شد ولی تحویلش ناموفق بود</b>", $text);
        self::assertStringEndsWith('منتظر تلاش دوباره است: در تاپیک «خطاها» با «🔁 تلاش دوباره برای تحویل»، یا از صفحه «سفارش‌ها» در پنل؛ مشتری بعد از تحویل خبردار می‌شود.', $text);
        self::assertSame('⚠️ ثبت شد ولی تحویلش ناموفق بود؛ منتظر تلاش دوباره است.', $this->popup());
        self::assertSame([], $this->telegram()->sentTo(self::TELEGRAM_ID), 'the customer hears of it delivered');
    }

    public function testAPlanOrServerGoneMeanwhileShowsTheStepBeforeAsItNowStands(): void
    {
        $id = $this->ali->id;
        $elsewhere = $this->sellingServer('هلند');
        $other = $this->plan(['name' => 'سه‌ماهه'], $elsewhere);

        $this->server->forceFill(['is_active' => false])->save();
        $this->send($this->press("uo:s:{$id}:{$this->plan->id}"));
        self::assertSame('هیچ‌کدام از سرورهای این پلن الان سرویس تازه‌ای نمی‌سازد؛ دلیل هر کدام در پنل، فرم پلن، کنار همان سرور آمده است.', $this->popup());
        self::assertSame([['سه‌ماهه · ۱۲۰٬۰۰۰ تومان'], ['⬅️ بازگشت']], $this->labels($this->edited()), 'the plans as they now stand');

        $this->planEntry($other, $this->server);
        $this->send($this->press("uo:c:{$id}:{$other->id}:{$this->server->id}:1:0"));
        self::assertSame('این سرور دیگر این پلن را تحویل نمی‌دهد.', $this->popup());
        self::assertSame([['هلند'], ['⬅️ بازگشت']], $this->labels($this->edited()), 'its servers as they now stand');

        $other->forceFill(['is_active' => false])->save();
        $this->send($this->press("uo:p:{$id}:{$other->id}:{$elsewhere->id}"));
        self::assertSame('هیچ پلنی الان قابل فروش نیست؛ پلن‌ها و سرورهایشان را در پنل ببینید.', $this->popup());
        self::assertSame(['answerCallbackQuery'], $this->calls(), 'the card as it is');
    }

    public function testTheirOwnAccountAndAnAgentsAreRefused(): void
    {
        $boss = User::query()->where('telegram_id', self::GROUP_ADMIN)->sole();
        $this->send($this->press("uo:o:{$boss->id}"));
        self::assertSame(ActorRefusedException::OWN_ACCOUNT, $this->popup());
        self::assertSame(['answerCallbackQuery'], $this->calls());

        $agent = $this->agent(overrides: ['telegram_id' => 9292, 'username' => 'agent_reza']);
        foreach (["uo:o:{$agent->id}", "uo:c:{$agent->id}:{$this->plan->id}:{$this->server->id}:1:0", "uo:y:{$agent->id}:{$this->plan->id}:{$this->server->id}:1:0:abc123"] as $data) {
            $this->send($this->press($data));
            self::assertSame(ActorRefusedException::AGENT_ACCOUNT, $this->popup(), $data);
        }
        self::assertFalse(Order::query()->withoutGlobalScopes()->exists());
    }

    public function testOnlyTheGroupsBotAdminsPress(): void
    {
        $refusal = GroupButtons::onlyAdmins(CustomerCards::MAY);
        $this->send($this->press("uo:o:{$this->ali->id}", self::GROUP_MEMBER));
        self::assertSame($refusal, $this->popup());

        $this->send($this->press("uo:y:{$this->ali->id}:{$this->plan->id}:{$this->server->id}:1:0:abc123", self::GROUP_MEMBER));
        self::assertSame($refusal, $this->popup());
        self::assertFalse(Order::query()->exists(), 'a press is no order of anyone but a bot admin');
    }

    /** A press on the card's buttons, by the report group's bot admin unless told otherwise. */
    private function press(string $data, int $from = self::GROUP_ADMIN): Update
    {
        return $this->groupTap($data, self::CARD, $this->threads['users'], $from);
    }

    /** The call that rewrote the card since the last send(). */
    private function edited(): int
    {
        $index = array_search('editMessageText', $this->calls(), true);

        return $index === false ? self::fail('The card was not rewritten: ' . implode(', ', $this->calls())) : $index;
    }

    /** @return list<list<string>> The labels of the buttons the n-th call put under the card, row by row. */
    private function labels(int $call): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'text'), $this->inlineKeyboard($call));
    }

    /** The callback data of «✔️ بله، ساخته شود» on the card as last rewritten. */
    private function yes(): string
    {
        return $this->callbacks($this->edited())[1];
    }

    /** The question the section last asked under the card. */
    private function prompt(): ReportMessage
    {
        return ReportMessage::query()->where('ref', 'like', 'ask:uo:%')->latest('id')->firstOrFail();
    }
}
