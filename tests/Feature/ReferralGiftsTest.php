<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Referrals\Enums\AlertReason;
use App\Modules\Referrals\Enums\StartGiftTrigger;
use App\Modules\Referrals\Models\ReferralAlert;
use App\Modules\Referrals\Models\ReferralBonus;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Referrals\Models\ReferralReward;
use App\Modules\Referrals\Services\ReferralGifts;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Services\Rewards;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;
use Tests\Support\FakeTelegram;

/**
 * Referral gifts: the renewal commission rate; the start gift on joining or on the first purchase, once per newcomer; the
 * plan reward per milestone of referrals who bought and kept it, each first purchase taking the next milestone (none lost
 * to two at once), retried while undeliverable; and fake-referral alerts (burst, idle), once per window, withholding the
 * gifts (never commissions) for good.
 */
final class ReferralGiftsTest extends BotTestCase
{
    /** The referrals' chats: someone other than the fixtures' customer, the referrer. */
    private const FRIEND = 777_000_300;

    private User $referrer;
    private string $code;
    private Server $server;
    private Plan $plan;

    /** How many newcomers join() brought so far: each a chat of its own. */
    private int $joined = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 12:00:00');
        $this->withoutQr();
        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->referralProgram();
        $this->referrer = $this->customer(['username' => 'ali']);
        $this->code = $this->service(ReferralService::class)->codeFor($this->referrer);
        $this->server = $this->sellingServer();
        $this->plan = $this->plan([], $this->server);
    }

    public function testARenewalEarnsAtItsOwnRateNothingAtZeroAndTheFirstPaymentRuleStands(): void
    {
        $this->referralProgram(overrides: ['referral_renewal_rate' => 5]);
        $friend = $this->friend();
        $service = $this->buy($friend, $this->plan, $this->server)->subscription ?? self::fail('nothing delivered');

        $renewal = $this->paidByCard($this->service(OrderService::class)->createRenewal($friend, $service, $this->plan));
        self::assertSame('6000.00', $this->referrer->balance(), '5% of the 120,000 renewal');
        self::assertSame(5, ReferralCommission::query()->where('payment_id', $renewal->id)->sole()->rate);

        $this->paidByCard($this->purchaseOrder($friend, $this->plan, $this->server));
        self::assertSame('18000.00', $this->referrer->balance(), 'a purchase still at 10%');

        $this->referralProgram(overrides: ['referral_renewal_rate' => 0]);
        $this->paidByCard($this->service(OrderService::class)->createRenewal($friend, $service, $this->plan));
        self::assertSame('18000.00', $this->referrer->balance(), 'renewals earn nothing at 0');

        $this->referralProgram(firstOnly: true, overrides: ['referral_renewal_rate' => 50]);
        $this->paidByCard($this->service(OrderService::class)->createRenewal($friend, $service, $this->plan));
        self::assertSame('18000.00', $this->referrer->balance(), 'with the first money in alone earning, a renewal later earns nothing');
        self::assertSame(2, ReferralCommission::query()->count());
    }

    public function testTheStartGiftOnJoiningGoesToBothOnceAndBothAreTold(): void
    {
        $this->referralProgram(overrides: ['referral_start_referrer' => 20_000, 'referral_start_invitee' => 10_000, 'referral_start_when' => 'join']);

        $this->send($this->message('/start ref_' . $this->code, [], self::FRIEND));

        $friend = User::query()->where('telegram_id', self::FRIEND)->sole();
        $bonus = ReferralBonus::query()->sole();
        self::assertSame([$friend->id, $this->referrer->id, '20000.00', '10000.00', StartGiftTrigger::Join], [$bonus->invitee_id, $bonus->referrer_id, $bonus->referrer_amount, $bonus->invitee_amount, $bonus->trigger]);
        self::assertSame(['20000.00', '10000.00'], [$this->referrer->balance(), $friend->balance()]);
        self::assertSame(sprintf(ReferralGifts::LINE_REFERRER, $friend->id), WalletTransaction::query()->where('user_id', $this->referrer->id)->sole()->description);
        self::assertSame(ReferralGifts::LINE_INVITEE, WalletTransaction::query()->where('user_id', $friend->id)->sole()->description);
        self::assertSame([
            self::text(BotText::ReferralJoined),
            self::text(BotText::ReferralStartGiftReferrer, ['amount' => Money::format('20000'), 'balance' => Messages::balance('20000.00')]),
        ], $this->telegram()->sentTo(self::CHAT));
        self::assertContains(self::text(BotText::ReferralStartGiftInvitee, ['amount' => Money::format('10000'), 'balance' => Messages::balance('10000.00')]), $this->telegram()->sentTo(self::FRIEND));

        $this->service(ReferralGifts::class)->joined($friend, $this->referrer);
        $this->buy($friend, $this->plan, $this->server);
        self::assertSame(1, ReferralBonus::query()->count(), 'once a newcomer');
        self::assertSame('20000.00', $this->referrer->balance());
    }

    public function testTheStartGiftOnAFirstPurchaseWaitsForItAndTheWalletPaysItToo(): void
    {
        $this->referralProgram(overrides: ['referral_start_referrer' => 20_000, 'referral_start_invitee' => 10_000]);
        $this->send($this->message('/start ref_' . $this->code, [], self::FRIEND));
        $friend = User::query()->where('telegram_id', self::FRIEND)->sole();
        self::assertSame(0, ReferralBonus::query()->count(), 'joining alone brings nothing');

        $this->paidByCard($this->topUpOrder($friend, '50000'));
        self::assertSame(0, ReferralBonus::query()->count(), 'a top-up is no purchase');

        $this->telegram()->reset();
        $this->buy($friend, $this->plan, $this->server);
        self::assertSame(StartGiftTrigger::Purchase, ReferralBonus::query()->sole()->trigger, 'paid from the wallet: a first purchase all the same');
        self::assertSame('25000.00', $this->referrer->balance(), "the gift and the top-up's 10% commission");
        self::assertContains(self::text(BotText::ReferralStartGiftInvitee, ['amount' => Money::format('10000'), 'balance' => Messages::balance($friend->balance())]), $this->telegram()->sentTo(self::FRIEND));

        $this->buy($friend, $this->plan, $this->server);
        self::assertSame(1, ReferralBonus::query()->count(), 'a second purchase is no first');
    }

    public function testAServiceGivenFreeIsNoFirstPurchase(): void
    {
        $this->referralProgram(overrides: ['referral_start_referrer' => 20_000]);
        $friend = $this->friend();

        $given = $this->service(Rewards::class)->give($friend, Reward::plan($this->plan), 'جایزه گردونه');
        self::assertSame(OrderPricing::Gift, $given->order?->pricing);
        self::assertSame(0, ReferralBonus::query()->count(), 'a gift order brings no start gift');

        $this->buy($friend, $this->plan, $this->server);
        self::assertSame('20000.00', ReferralBonus::query()->sole()->referrer_amount, 'their first purchase does');
    }

    public function testAFreePlanAloneBringsNoStartGift(): void
    {
        $this->referralProgram(overrides: ['referral_start_referrer' => 20_000, 'referral_start_invitee' => 10_000]);
        $friend = $this->friend();

        $this->buy($friend, $this->freePlan(), $this->server);

        self::assertSame(0, ReferralBonus::query()->count(), 'a free trial is no first purchase here');
        self::assertSame(['0.00', '0.00'], [$this->referrer->balance(), $friend->balance()]);
    }

    public function testThePaidPurchaseAfterAFreePlanIsTheFirstAndBringsTheGift(): void
    {
        $this->referralProgram(overrides: ['referral_start_referrer' => 20_000]);
        $friend = $this->friend();
        $this->buy($friend, $this->freePlan(), $this->server);

        $this->buy($friend, $this->plan, $this->server);

        $bonus = ReferralBonus::query()->sole();
        self::assertSame([StartGiftTrigger::Purchase, '20000.00'], [$bonus->trigger, $bonus->referrer_amount], 'the free plan did not use the gift up');
    }

    public function testOfTwoFirstPurchasesSettledInTheSameMomentOneBringsTheGift(): void
    {
        $this->referralProgram(overrides: ['referral_start_referrer' => 20_000]);
        $friend = $this->friend();
        $card = $this->cardMethod();
        $first = $this->receipt($this->cardPayment($this->purchaseOrder($friend, $this->plan, $this->server), $card));
        $second = $this->receipt($this->cardPayment($this->purchaseOrder($friend, $this->plan, $this->server), $card));

        // The later order approved first: alone paid then, it is the first; the earlier one, approved in the same second,
        // is paid before it by number — both take themselves for the first, and the newcomer's one row decides.
        $this->service(PaymentService::class)->approve($second, 'admin');
        $this->service(PaymentService::class)->approve($first, 'admin');

        self::assertSame(1, ReferralBonus::query()->count());
        self::assertSame('44000.00', $this->referrer->balance(), 'one gift, and the two payments\' commissions');
    }

    public function testAReferrerUnderAnAlertGetsNoStartGiftNorLaterOnceItIsCleared(): void
    {
        $this->referralProgram(overrides: ['referral_start_referrer' => 20_000, 'referral_start_invitee' => 10_000]);
        $friend = $this->friend();
        $alert = $this->referralAlert($this->referrer);

        $this->telegram()->reset();
        $this->paidByCard($this->purchaseOrder($friend, $this->plan, $this->server));

        $bonus = ReferralBonus::query()->sole();
        self::assertSame(['0.00', '0.00'], [$bonus->referrer_amount, $bonus->invitee_amount], 'withheld — and decided');
        self::assertSame(['12000.00', '0.00'], [$this->referrer->balance(), $friend->balance()], 'the commission stands: real money came in');
        self::assertNotContains(self::text(BotText::ReferralStartGiftInvitee, ['amount' => Money::format('10000'), 'balance' => Messages::balance('10000.00')]), $this->telegram()->sentTo(self::FRIEND));

        $alert->delete();
        $this->service(ReferralGifts::class)->purchased($this->paidByCard($this->purchaseOrder($friend, $this->plan, $this->server)));
        self::assertSame('24000.00', $this->referrer->balance(), 'cleared, what was withheld is not given later');
    }

    public function testThePlanRewardAtEachMilestoneOnceAndTheServiceSentWithIt(): void
    {
        $prize = $this->plan(['name' => 'جایزه ماهانه'], $this->server);
        $this->referralProgram(overrides: ['referral_reward_every' => 2, 'referral_reward_plan_id' => $prize->id]);
        [$one, $two, $three, $four] = $this->friends(4);

        $this->buy($one, $this->plan, $this->server);
        self::assertSame(0, ReferralReward::query()->count(), 'one of two');

        $this->telegram()->reset();
        $this->buy($two, $this->plan, $this->server);
        $reward = ReferralReward::query()->sole();
        $gift = Order::query()->findOrFail($reward->order_id);
        self::assertSame([2, $this->referrer->id, OrderPricing::Gift, OrderStatus::Fulfilled, $prize->id], [$reward->milestone, $gift->user_id, $gift->pricing, $gift->status, $gift->plan_id]);
        $told = $this->telegram()->sentTo(self::CHAT);
        self::assertSame(self::text(BotText::ReferralRewarded, ['buyers' => '۲', 'plan' => 'جایزه ماهانه']), $told[0] ?? null, 'told first what it is for');
        self::assertCount(2, $told, 'then its service, as any delivered order');

        $this->buy($two, $this->plan, $this->server);
        $this->buy($three, $this->plan, $this->server);
        self::assertSame(1, ReferralReward::query()->count(), "a second purchase is no referral's first, and three is no milestone");

        $this->buy($four, $this->plan, $this->server);
        self::assertSame([2, 4], ReferralReward::query()->orderBy('milestone')->pluck('milestone')->all());
        self::assertSame(2, $this->service(ReferralService::class)->rewardsOf($this->referrer));
    }

    public function testThePlanRewardCountsOnlyReferralsWhoBoughtSomethingWithAPrice(): void
    {
        $prize = $this->plan(['name' => 'جایزه'], $this->server);
        $this->referralProgram(overrides: ['referral_reward_every' => 2, 'referral_reward_plan_id' => $prize->id]);
        [$one, $two, $three] = $this->friends(3);

        $this->buy($one, $this->freePlan(), $this->server);
        $this->buy($two, $this->plan, $this->server);
        self::assertSame(0, ReferralReward::query()->count(), 'a free plan makes no buyer: one of two');

        $this->buy($three, $this->plan, $this->server);
        self::assertSame([2], ReferralReward::query()->pluck('milestone')->all());
    }

    public function testARewardPlanThatCannotBeDeliveredIsTriedAgainByTheNextFirstPurchase(): void
    {
        $prize = $this->plan(['name' => 'جایزه']);
        $this->referralProgram(overrides: ['referral_reward_every' => 1, 'referral_reward_plan_id' => $prize->id]);
        [$one, $two] = $this->friends(2);

        $this->buy($one, $this->plan, $this->server);
        self::assertSame(0, ReferralReward::query()->count(), 'on no server that delivers: not recorded');
        self::assertFalse(Order::query()->where('user_id', $this->referrer->id)->exists(), 'nothing ordered');

        $this->planEntry($prize, $this->server);
        $this->buy($two, $this->plan, $this->server);
        self::assertSame([1], ReferralReward::query()->whereNotNull('order_id')->pluck('milestone')->all(), 'the milestone left is given by the next first purchase');
    }

    public function testTwoFirstPurchasesThatCrossTwoMilestonesAtOnceGiveBoth(): void
    {
        $prize = $this->plan(['name' => 'جایزه'], $this->server);
        [$one, $two] = $this->friends(2);
        // Both paid before either's gifts were looked at: each then counts two buyers.
        $first = $this->buy($one, $this->plan, $this->server);
        $second = $this->buy($two, $this->plan, $this->server);
        $this->referralProgram(overrides: ['referral_reward_every' => 1, 'referral_reward_plan_id' => $prize->id]);

        $gifts = $this->service(ReferralGifts::class);
        $gifts->purchased($second->payments()->firstOrFail());
        $gifts->purchased($first->payments()->firstOrFail());

        self::assertSame([1, 2], ReferralReward::query()->whereNotNull('order_id')->orderBy('milestone')->pluck('milestone')->all(), 'each first purchase takes the next milestone: none lost');
        self::assertSame(2, Order::query()->where('user_id', $this->referrer->id)->count());
    }

    public function testALoweredNumberGivesOneMilestoneAFirstPurchaseNeverSeveralAtOnce(): void
    {
        $prize = $this->plan(['name' => 'جایزه'], $this->server);
        $this->referralProgram(overrides: ['referral_reward_every' => 5, 'referral_reward_plan_id' => $prize->id]);
        $friends = $this->friends(6);
        foreach (array_slice($friends, 0, 4) as $friend) {
            $this->buy($friend, $this->plan, $this->server);
        }
        self::assertSame(0, ReferralReward::query()->count(), 'four of five');

        $this->referralProgram(overrides: ['referral_reward_every' => 2, 'referral_reward_plan_id' => $prize->id]);
        $this->buy($friends[4], $this->plan, $this->server);
        self::assertSame([2], ReferralReward::query()->whereNotNull('order_id')->pluck('milestone')->all(), 'five buyers at every two: one milestone, not two');

        $this->buy($friends[5], $this->plan, $this->server);
        self::assertSame([2, 4], ReferralReward::query()->whereNotNull('order_id')->orderBy('milestone')->pluck('milestone')->all(), 'then the next, one a first purchase');
        self::assertSame(2, Order::query()->where('user_id', $this->referrer->id)->count());
    }

    public function testARefundedPurchaseMakesNoBuyerForThePlanReward(): void
    {
        $prize = $this->plan(['name' => 'جایزه'], $this->server);
        $this->referralProgram(overrides: ['referral_reward_every' => 2, 'referral_reward_plan_id' => $prize->id]);
        [$one, $two] = $this->friends(2);

        $refunded = $this->buy($one, $this->plan, $this->server);
        self::assertTrue($this->service(PaymentService::class)->refund($refunded->payments()->firstOrFail(), 'admin', null));
        $this->buy($two, $this->plan, $this->server);
        self::assertSame(0, ReferralReward::query()->count(), 'one buyer of two: the refunded purchase counts for nothing');

        $this->buy($one, $this->plan, $this->server);
        self::assertSame([2], ReferralReward::query()->whereNotNull('order_id')->pluck('milestone')->all(), 'their purchase after the refund is their first kept one');
    }

    public function testARewardWhoseDeliveryBrokeIsGivenOnceAndWaitsForSupport(): void
    {
        $elsewhere = $this->sellingServer('فرانسه');
        $prize = $this->plan(['name' => 'جایزه'], $elsewhere);
        $this->referralProgram(overrides: ['referral_reward_every' => 2, 'referral_reward_plan_id' => $prize->id]);
        [$one, $two, $three] = $this->friends(3);
        // The prize's delivery breaks on a fault of the shop's own, after its order was paid.
        FakeProvider::$onCall = static fn(int $server) => $server === $elsewhere->id ? throw new \RuntimeException('A fault of the shop\'s own.') : null;

        $this->buy($one, $this->plan, $this->server);
        $this->buy($two, $this->plan, $this->server);
        $gift = Order::query()->where('user_id', $this->referrer->id)->sole();
        self::assertSame([OrderStatus::Failed, OrderService::DELIVERY_BROKEN], [$gift->status, $gift->notes], 'paid, waiting for support\'s retry');
        self::assertSame($gift->id, ReferralReward::query()->sole()->order_id, 'the milestone given');

        $this->buy($three, $this->plan, $this->server);
        self::assertSame(1, Order::query()->where('user_id', $this->referrer->id)->count(), 'never given a second time');
    }

    public function testAReferrerUnderAnAlertGetsNoPlanRewardNorLater(): void
    {
        $prize = $this->plan(['name' => 'جایزه'], $this->server);
        $this->referralProgram(overrides: ['referral_reward_every' => 1, 'referral_reward_plan_id' => $prize->id]);
        [$one, $two] = $this->friends(2);
        $alert = $this->referralAlert($this->referrer, ['reason' => AlertReason::Idle, 'count' => 20]);

        $this->buy($one, $this->plan, $this->server);
        self::assertNull(ReferralReward::query()->sole()->order_id, 'withheld, and decided');

        $alert->delete();
        $this->service(ReferralGifts::class)->purchased($this->paidByCard($this->purchaseOrder($one, $this->plan, $this->server)));
        self::assertSame(0, ReferralReward::query()->whereNotNull('order_id')->count(), 'cleared, the milestone withheld is not given later');
        $this->buy($two, $this->plan, $this->server);
        self::assertSame(1, ReferralReward::query()->whereNotNull('order_id')->count(), 'the next one is');
    }

    public function testABurstOfJoinsRaisesOneAlertAWindowReportedUnderTheNewcomers(): void
    {
        $this->reportGroup();
        $this->referralProgram(overrides: ['referral_alert_burst' => 3, 'referral_alert_minutes' => 30, 'referral_alert_idle' => 0]);

        $this->join(2);
        self::assertSame(0, ReferralAlert::query()->count(), 'two of three');
        $this->join(1);
        $alert = ReferralAlert::query()->sole();
        self::assertSame([$this->referrer->id, AlertReason::Burst, 3], [$alert->referrer_id, $alert->reason, $alert->count]);
        $report = ReportMessage::query()->where('topic', Topic::Users->value)->latest('id')->firstOrFail();
        self::assertSame(4, ReportMessage::query()->where('topic', Topic::Users->value)->count(), 'three newcomers, then the alert after the third');
        self::assertStringContainsString('هشدار زیرمجموعه‌گیری', $report->text);
        self::assertStringContainsString('۳ زیرمجموعه در ۳۰ دقیقه', $report->text);

        $this->join(3);
        self::assertSame(1, ReferralAlert::query()->count(), 'the burst goes on: no alert again');

        Carbon::setTestNow(now()->addMinutes(31));
        $this->join(3);
        self::assertSame(2, ReferralAlert::query()->count(), 'a new burst in a new window');
    }

    public function testManyReferralsWhoNeverBuyRaiseAnAlertAndABuyerAmongThemEndsIt(): void
    {
        $this->referralProgram(overrides: ['referral_alert_burst' => 0, 'referral_alert_idle' => 3, 'referral_alert_minutes' => 60]);

        $this->join(3);
        self::assertSame([AlertReason::Idle, 3], [ReferralAlert::query()->sole()->reason, ReferralAlert::query()->sole()->count]);

        Carbon::setTestNow(now()->addMinutes(61));
        $this->buy(User::query()->where('referred_by', $this->referrer->id)->firstOrFail(), $this->plan, $this->server);
        $this->join(3);
        self::assertSame(1, ReferralAlert::query()->count(), 'one of them bought: nobody idles');
    }

    public function testReferralsWhoOnlyTookAFreePlanStillIdle(): void
    {
        $this->referralProgram(overrides: ['referral_alert_burst' => 0, 'referral_alert_idle' => 3, 'referral_alert_minutes' => 60]);

        $this->join(3);
        Carbon::setTestNow(now()->addMinutes(61));
        $this->buy(User::query()->where('referred_by', $this->referrer->id)->firstOrFail(), $this->freePlan(), $this->server);
        $this->join(3);

        self::assertSame(2, ReferralAlert::query()->where('reason', AlertReason::Idle->value)->count(), 'a free trial is no purchase');
    }

    public function testTheBotScreenSaysTheRenewalRateTheStartGiftAndThePlanReward(): void
    {
        $prize = $this->plan(['name' => 'جایزه ماهانه']);
        $this->referralProgram(overrides: [
            'referral_renewal_rate' => 0,
            'referral_start_referrer' => 20_000,
            'referral_start_invitee' => 10_000,
            'referral_reward_every' => 5,
            'referral_reward_plan_id' => $prize->id,
        ]);

        $this->send($this->tap('menu:affiliates'));

        $terms = implode("\n", [
            self::text(BotText::ReferralTermsEvery, ['rate' => '۱۰٪']),
            self::text(BotText::ReferralTermsNoRenewal),
            self::text(BotText::ReferralTermsGiftBoth, ['trigger' => 'با اولین خرید', 'you' => Money::format('20000'), 'friend' => Money::format('10000')]),
            self::text(BotText::ReferralTermsReward, ['invites' => '۵', 'plan' => 'جایزه ماهانه']),
        ]);
        self::assertSame([self::text(BotText::ReferralScreen, [
            'terms' => $terms,
            'link' => 'https://t.me/amo_shop_bot?start=ref_' . $this->code,
            'referrals' => '۰',
            'earned' => Money::format('0'),
        ])], $this->said());

        $this->referralProgram(overrides: ['referral_start_invitee' => 10_000, 'referral_start_when' => 'join', 'referral_renewal_rate' => 5]);
        $this->send($this->tap('menu:affiliates'));
        self::assertStringContainsString(self::text(BotText::ReferralTermsRenewal, ['rate' => '۵٪']), $this->said()[0]);
        self::assertStringContainsString(self::text(BotText::ReferralTermsGiftFriend, ['trigger' => 'با عضویت', 'friend' => Money::format('10000')]), $this->said()[0]);
    }

    public function testInAnAgentsShopItsOwnProgramGivesItsOwnStartGiftByItsOwnBot(): void
    {
        $bot = $this->agentBot();
        [$owner, $code] = CurrentBot::run($bot, function (): array {
            $this->referralProgram(overrides: ['referral_start_referrer' => 15_000, 'referral_start_when' => 'join']);
            $owner = $this->customer(['telegram_id' => 5050, 'username' => 'agents_customer']);

            return [$owner, $this->service(ReferralService::class)->codeFor($owner)];
        });

        $this->sendTo($bot, $this->message('/start ref_' . $code, [], self::FRIEND));

        self::assertSame('15000.00', CurrentBot::run($bot, fn(): string => $owner->balance()));
        self::assertSame($bot->id, ReferralBonus::query()->withoutGlobalScope(CurrentBot::SCOPE)->sole()->bot_id, "the agent's shop's");
        self::assertSame(0, ReferralBonus::query()->count(), "the main shop's program gives nothing of it");
        $told = array_keys(array_filter($this->telegram()->history, static fn(array $call): bool => ($call['params']['chat_id'] ?? null) === '5050'));
        self::assertNotSame([], $told);
        self::assertSame([FakeTelegram::AGENT_TOKEN], array_values(array_unique(array_map(fn(int $i): string => $this->telegram()->tokenOf($i), $told))), "told by the agent's bot");
    }

    /** A customer of the shop the referrer's link brought. */
    private function friend(int $offset = 0): User
    {
        return $this->customer(['telegram_id' => self::FRIEND + $offset, 'referred_by' => $this->referrer->id]);
    }

    /** A free trial plan (price 0) on the shop's server. */
    private function freePlan(): Plan
    {
        return $this->plan(['name' => 'تست رایگان', 'price' => '0.00'], $this->server);
    }

    /** @return list<User> */
    private function friends(int $count): array
    {
        return array_map(fn(int $i): User => $this->friend($i), range(1, $count));
    }

    /** Newcomers joining through the referrer's link, one after the other — as the bot registers them. */
    private function join(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->send($this->message('/start ref_' . $this->code, [], self::FRIEND + 100 + $this->joined++));
        }
    }
}
