<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerCards;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Telegram\Reports\GroupButtons;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * «🧾 سوابق» on a customer's card: their latest orders, payments, tickets and referrals, a tab each, newest first, with
 * what they add up to — the tabs under it, the one shown marked. It only reads, so every account has it: the admin's own
 * and an agent's too. Only the report group's bot admins open it.
 */
final class CustomerHistoryTest extends BotTestCase
{
    /** A card of the bot's in the group, under which the buttons are pressed. */
    private const CARD = 5252;

    /** The day every row of these tests is written on (2026-10-07), as the card says it. */
    private const TODAY = '۱۵ مهر ۱۴۰۵';

    /** @var array<string, int> */
    private array $threads;

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->threads = $this->reportGroup();
        $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);
        $this->ali = $this->customer(['telegram_id' => 7070, 'username' => 'ali']);
    }

    public function testTheOrdersTabSaysWhatEachWasForAndWhatTheyBought(): void
    {
        $server = $this->fakeServer();
        $plan = $this->plan();
        $service = $this->subscription($this->ali, $plan, $server, 'ali_vpn');
        $bought = $this->purchaseOrder($this->ali, $plan, $server, ['status' => OrderStatus::Fulfilled]);
        $topUp = $this->topUpOrder($this->ali, '50000', ['status' => OrderStatus::Fulfilled]);
        $renewal = $this->order(OrderType::Renewal, OrderStatus::Fulfilled, '120000', ['plan_id' => $plan->id, 'server_id' => $server->id, 'subscription_id' => $service->id]);
        $extra = $this->order(OrderType::ExtraTraffic, OrderStatus::Failed, '30000', ['server_id' => $server->id, 'subscription_id' => $service->id, 'traffic_bytes' => 10 * Traffic::GIGABYTE]);
        $built = $this->order(OrderType::Purchase, OrderStatus::Pending, '90000', ['server_id' => $server->id, 'traffic_bytes' => 50 * Traffic::GIGABYTE, 'days' => 30]);
        $refunded = $this->purchaseOrder($this->ali, $plan, $server, ['status' => OrderStatus::Refunded]);
        $traffic = $this->order(OrderType::Traffic, OrderStatus::Cancelled, '150000', ['traffic_bytes' => 50 * Traffic::GIGABYTE]);
        $this->purchaseOrder($this->customer(['telegram_id' => 8080]), $plan, $server, ['status' => OrderStatus::Fulfilled]);

        $this->send($this->press("uh:o:{$this->ali->id}"));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertSame('HTML', $this->params(0)['parse_mode']);
        $today = self::TODAY;
        self::assertSame(implode("\n", [
            "⚙️ <b>مدیریت کاربر › سوابق › سفارش‌ها</b> · \u{200E}#{$this->ali->id}",
            "👤 <a href=\"tg://user?id=7070\">Ali</a> · \u{200E}@ali · <code>7070</code>",
            '',
            "سفارش \u{200E}#{$traffic->id} · خرید حجم نمایندگی (۵۰ گیگابایت) · ۱۵۰٬۰۰۰ تومان · لغوشده · {$today}",
            "سفارش \u{200E}#{$refunded->id} · خرید یک‌ماهه · ۱۲۰٬۰۰۰ تومان · بازپرداخت‌شده · {$today}",
            "سفارش \u{200E}#{$built->id} · خرید سرویس دلخواه · ۵۰ گیگ · ۳۰ روز · ۹۰٬۰۰۰ تومان · در انتظار پرداخت · {$today}",
            "سفارش \u{200E}#{$extra->id} · حجم اضافه ۱۰ گیگابایت برای <code>ali_vpn</code> · ۳۰٬۰۰۰ تومان · ناموفق · {$today}",
            "سفارش \u{200E}#{$renewal->id} · تمدید <code>ali_vpn</code> · ۱۲۰٬۰۰۰ تومان · تکمیل‌شده · {$today}",
            "سفارش \u{200E}#{$topUp->id} · شارژ کیف پول · ۵۰٬۰۰۰ تومان · تکمیل‌شده · {$today}",
            "سفارش \u{200E}#{$bought->id} · خرید یک‌ماهه · ۱۲۰٬۰۰۰ تومان · تکمیل‌شده · {$today}",
            '',
            '💰 خریدها: ۲۷۰٬۰۰۰ تومان در ۳ سفارش پرداخت‌شده، جدا از شارژ کیف پول',
        ]), $this->params(0)['text'], 'newest first; what they bought: paid and kept, the top-up\'s money spent in the others');
        self::assertSame([['💳 پرداخت‌ها', '• سفارش‌ها'], ['👥 زیرمجموعه‌ها', '🎫 تیکت‌ها'], ['⬅️ بازگشت']], $this->labels(), 'the tab shown marked, each row\'s first on the right');
        self::assertSame(["uh:p:{$this->ali->id}", "uh:o:{$this->ali->id}", "uh:r:{$this->ali->id}", "uh:t:{$this->ali->id}", "um:o:{$this->ali->id}"], $this->callbacks(0));
    }

    public function testATabShowsTheLatestTenAndSaysHowManyAreOlder(): void
    {
        $orders = [];
        foreach (range(1, 12) as $n) {
            $orders[] = $this->topUpOrder($this->ali, (string) ($n * 1000));
        }

        $this->send($this->press("uh:o:{$this->ali->id}"));

        $lines = explode("\n", $this->params(0)['text']);
        self::assertStringStartsWith("سفارش \u{200E}#{$orders[11]->id} · شارژ کیف پول · ۱۲٬۰۰۰ تومان · در انتظار پرداخت", $lines[3]);
        self::assertStringStartsWith("سفارش \u{200E}#{$orders[2]->id} ·", $lines[12], 'the tenth');
        self::assertSame(['… و ۲ سفارش قدیمی‌تر', '', '💰 خریدها: ۰ تومان در ۰ سفارش پرداخت‌شده، جدا از شارژ کیف پول'], array_slice($lines, 13));
    }

    public function testThePaymentsTabSaysEachAndTheMoneyThatCameIn(): void
    {
        $plan = $this->plan();
        $server = $this->fakeServer();
        $card = $this->cardMethod();
        $wallet = $this->walletMethod();
        $byCard = $this->cardPayment($this->purchaseOrder($this->ali, $plan, $server, ['status' => OrderStatus::Fulfilled]), $card, ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $fromWallet = $this->cardPayment($this->purchaseOrder($this->ali, $plan, $server, ['status' => OrderStatus::Fulfilled]), $wallet, ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $inReview = $this->cardPayment($this->topUpOrder($this->ali, '50000'), $card, ['status' => PaymentStatus::AwaitingReview]);
        $refunded = $this->cardPayment($this->purchaseOrder($this->ali, $plan, $server, ['status' => OrderStatus::Refunded]), $card, ['status' => PaymentStatus::Refunded]);
        $this->cardPayment($this->topUpOrder($this->customer(['telegram_id' => 8080]), '70000'), $card, ['status' => PaymentStatus::Paid]);

        $this->send($this->press("uh:p:{$this->ali->id}"));

        $today = self::TODAY;
        self::assertSame(implode("\n", [
            "⚙️ <b>مدیریت کاربر › سوابق › پرداخت‌ها</b> · \u{200E}#{$this->ali->id}",
            "👤 <a href=\"tg://user?id=7070\">Ali</a> · \u{200E}@ali · <code>7070</code>",
            '',
            "پرداخت \u{200E}#{$refunded->id} · ۱۲۰٬۰۰۰ تومان · کارت به کارت (ملت) · بازپرداخت‌شده · {$today}",
            "پرداخت \u{200E}#{$inReview->id} · ۵۰٬۰۰۰ تومان · کارت به کارت (ملت) · در انتظار بررسی · {$today}",
            "پرداخت \u{200E}#{$fromWallet->id} · ۱۲۰٬۰۰۰ تومان · " . htmlspecialchars($wallet->label) . " · پرداخت‌شده · {$today}",
            "پرداخت \u{200E}#{$byCard->id} · ۱۲۰٬۰۰۰ تومان · کارت به کارت (ملت) · پرداخت‌شده · {$today}",
            '',
            '💳 واریزها: ۱۲۰٬۰۰۰ تومان در ۱ پرداخت موفق، جدا از کیف پول',
        ]), $this->params(0)['text'], 'what came in: paid and not given back, the wallet\'s money having come in before');
        self::assertSame([['• پرداخت‌ها', '🧾 سفارش‌ها'], ['👥 زیرمجموعه‌ها', '🎫 تیکت‌ها'], ['⬅️ بازگشت']], $this->labels());
    }

    public function testTheTicketsTabSaysEachLatestWrittenFirstAndHowManyWait(): void
    {
        $open = $this->ticket($this->ali);
        Carbon::setTestNow('2026-10-08 12:00:00');
        $long = $this->ticket($this->ali, 'سرعت <b>سرور آلمان</b> شب‌ها خیلی پایین می‌آید و قطع می‌شود', overrides: ['status' => TicketStatus::Answered]);
        $closed = $this->ticket($this->ali, 'تمدید', overrides: ['status' => TicketStatus::Closed, 'last_message_at' => Carbon::parse('2026-10-06 12:00:00')]);
        $this->ticket($this->customer(['telegram_id' => 8080]));

        $this->send($this->press("uh:t:{$this->ali->id}"));

        self::assertSame(implode("\n", [
            "⚙️ <b>مدیریت کاربر › سوابق › تیکت‌ها</b> · \u{200E}#{$this->ali->id}",
            "👤 <a href=\"tg://user?id=7070\">Ali</a> · \u{200E}@ali · <code>7070</code>",
            '',
            "تیکت \u{200E}#{$long->id} · «سرعت &lt;b&gt;سرور آلمان&lt;/b&gt; شب‌ها خیلی پایین…» · پاسخ‌داده‌شده · ۱۶ مهر ۱۴۰۵",
            "تیکت \u{200E}#{$open->id} · «قطعی اتصال» · در انتظار پاسخ · ۱۵ مهر ۱۴۰۵",
            "تیکت \u{200E}#{$closed->id} · «تمدید» · بسته‌شده · ۱۴ مهر ۱۴۰۵",
            '',
            '🎫 ۳ تیکت · ۱ در انتظار پاسخ',
        ]), $this->params(0)['text'], 'the subject escaped and cut; the date of its last word');
        self::assertSame([['💳 پرداخت‌ها', '🧾 سفارش‌ها'], ['👥 زیرمجموعه‌ها', '• تیکت‌ها'], ['⬅️ بازگشت']], $this->labels());
    }

    public function testTheReferralsTabSaysWhoBroughtThemWhomTheyBroughtAndWhatItEarned(): void
    {
        $sara = $this->customer(['telegram_id' => 8080, 'username' => 'sara', 'first_name' => 'Sara']);
        $this->ali->forceFill(['referred_by' => $sara->id])->save();
        $plan = $this->plan();
        $server = $this->fakeServer();
        $card = $this->cardMethod();
        $paid = fn(Order $order): int => $this->cardPayment($order, $card, ['status' => PaymentStatus::Paid])->id;
        $reza = $this->customer(['telegram_id' => 8181, 'first_name' => 'Reza', 'referred_by' => $this->ali->id]);
        $purchase = $paid($this->purchaseOrder($reza, $plan, $server, ['status' => OrderStatus::Fulfilled]));
        $mina = $this->customer(['telegram_id' => 8282, 'first_name' => 'Mina', 'referred_by' => $this->ali->id]);
        $this->purchaseOrder($mina, $plan, $server, ['status' => OrderStatus::Refunded]);
        $leila = $this->webCustomer(['first_name' => 'Leila', 'last_name' => null, 'email' => 'leila@example.com', 'referred_by' => $this->ali->id]);
        ReferralCommission::query()->create(['payment_id' => $purchase, 'referrer_id' => $this->ali->id, 'rate' => 10, 'commission' => '12000']);
        ReferralCommission::query()->create(['payment_id' => $paid($this->topUpOrder($reza, '30000')), 'referrer_id' => $this->ali->id, 'rate' => 10, 'commission' => '3000']);
        ReferralCommission::query()->create(['payment_id' => $paid($this->topUpOrder($this->ali, '90000')), 'referrer_id' => $sara->id, 'rate' => 10, 'commission' => '9000']);

        $this->send($this->press("uh:r:{$this->ali->id}"));

        $today = self::TODAY;
        self::assertSame(implode("\n", [
            "⚙️ <b>مدیریت کاربر › سوابق › زیرمجموعه‌ها</b> · \u{200E}#{$this->ali->id}",
            "👤 <a href=\"tg://user?id=7070\">Ali</a> · \u{200E}@ali · <code>7070</code>",
            '',
            "🤝 معرف: <a href=\"tg://user?id=8080\">Sara</a> · \u{200E}@sara · <code>8080</code>",
            '👥 زیرمجموعه: ۳ نفر · ۱ نفر خرید کرده',
            '💰 پورسانت دریافتی: ۱۵٬۰۰۰ تومان',
            '',
            "▫️ خرید نکرده · Leila · leila@example.com · \u{200E}<code>#{$leila->id}</code> · {$today}",
            "▫️ خرید نکرده · <a href=\"tg://user?id=8282\">Mina</a> · بدون نام کاربری · <code>8282</code> · {$today}",
            "🛍 خرید کرده · <a href=\"tg://user?id=8181\">Reza</a> · بدون نام کاربری · <code>8181</code> · {$today}",
        ]), $this->params(0)['text'], 'bought: a purchase with a price, kept; earned: the commissions credited to them');
        self::assertSame([['💳 پرداخت‌ها', '🧾 سفارش‌ها'], ['• زیرمجموعه‌ها', '🎫 تیکت‌ها'], ['⬅️ بازگشت']], $this->labels());
    }

    public function testEveryTabSaysWhenItHasNothingYet(): void
    {
        $empty = [
            'o' => ['سفارش‌ها', 'هنوز سفارشی ندارد.'],
            'p' => ['پرداخت‌ها', 'هنوز پرداختی ندارد.'],
            't' => ['تیکت‌ها', 'هنوز تیکتی ندارد.'],
            'r' => ['زیرمجموعه‌ها', "🤝 معرف: ندارد\n👥 زیرمجموعه: هنوز کسی را با لینکش نیاورده است."],
        ];
        foreach ($empty as $tab => [$name, $words]) {
            $this->send($this->press("uh:{$tab}:{$this->ali->id}"));

            self::assertSame(implode("\n", [
                "⚙️ <b>مدیریت کاربر › سوابق › {$name}</b> · \u{200E}#{$this->ali->id}",
                "👤 <a href=\"tg://user?id=7070\">Ali</a> · \u{200E}@ali · <code>7070</code>",
                '',
                $words,
            ]), $this->params(0)['text'], $tab);
            self::assertNull($this->popup(), "{$tab}: the press answered without a word");
        }
    }

    public function testItReadsEveryAccountAndOnlyForTheGroupsBotAdmins(): void
    {
        $boss = User::query()->where('telegram_id', self::GROUP_ADMIN)->sole();
        $agent = $this->agent(overrides: ['telegram_id' => 9292, 'username' => 'agent_reza']);
        foreach ([$boss, $agent] as $user) {
            $this->send($this->press("uh:t:{$user->id}"));

            self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), "#{$user->id}: theirs is read too");
            self::assertStringEndsWith("\n\nهنوز تیکتی ندارد.", $this->params(0)['text']);
            self::assertSame("um:o:{$user->id}", $this->callbacks(0)[4]);
        }

        $this->send($this->press("uh:o:{$this->ali->id}", from: self::GROUP_MEMBER));
        self::assertSame(['answerCallbackQuery'], $this->calls());
        self::assertSame(GroupButtons::onlyAdmins('کاربرها را مدیریت کنند'), $this->popup());

        foreach (["uh:x:{$this->ali->id}", "uh:o:{$this->ali->id}:2"] as $data) {
            $this->send($this->press($data));
            self::assertSame(['answerCallbackQuery'], $this->calls(), "{$data}: no tab of this card");
            self::assertNull($this->popup());
        }
    }

    /**
     * An order of the customer's, as its kind leaves it.
     *
     * @param array<string, mixed> $overrides
     */
    private function order(OrderType $type, OrderStatus $status, string $amount, array $overrides = []): Order
    {
        return Order::query()->create($overrides + ['user_id' => $this->ali->id, 'type' => $type, 'status' => $status, 'amount' => $amount]);
    }

    /** @return list<list<string>> The labels of the last rewrite's buttons, row by row. */
    private function labels(): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'text'), $this->inlineKeyboard(0));
    }

    /** A press on the card's buttons, by the report group's bot admin unless told otherwise. */
    private function press(string $data, int $from = self::GROUP_ADMIN): Update
    {
        return $this->groupTap($data, self::CARD, $this->threads['users'], $from);
    }
}
