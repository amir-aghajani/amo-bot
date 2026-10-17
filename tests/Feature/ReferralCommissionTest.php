<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\RefundRefusedException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\UserActions;
use App\Modules\Users\Services\WalletService;
use App\Support\Money;
use Tests\BotTestCase;

/**
 * Referral commissions: the rate of money that came in (never a wallet purchase), credited once per payment and announced once,
 * the first-payment-only rule, one level only, taken back by a top-up's refund, whose money leaves the shop (refused once
 * spent, as the top-up is) — a purchase's refund, its money kept in the shop as wallet credit, leaves it —, never in the
 * payment's way when it fails.
 */
final class ReferralCommissionTest extends BotTestCase
{
    private const FRIEND = 777_000_222;

    private User $referrer;
    private User $friend;
    private Plan $plan;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->referralProgram();
        $this->referrer = $this->customer();
        $this->friend = $this->customer(['telegram_id' => self::FRIEND, 'referred_by' => $this->referrer->id]);
        $this->server = $this->sellingServer();
        $this->plan = $this->plan([], $this->server);
    }

    public function testACardPaymentEarnsTheReferrerTheRateOnceAndTellsThemOnce(): void
    {
        $payment = $this->paidByCard($this->purchaseOrder($this->friend, $this->plan, $this->server));

        self::assertSame('12000.00', $this->referrer->balance(), '10% of 120,000');
        $commission = ReferralCommission::query()->where('payment_id', $payment->id)->firstOrFail();
        self::assertSame([10, '12000.00', $this->referrer->id], [$commission->rate, $commission->commission, $commission->referrer_id], 'whom it was credited to, kept');
        self::assertSame('12000.00', $this->service(ReferralService::class)->statsFor($this->referrer)['earned'], "the payment's customer's referrer earned it");
        $line = WalletTransaction::query()->where('user_id', $this->referrer->id)->latest('id')->firstOrFail();
        self::assertSame(WalletService::describeReferral($payment), $line->description);

        $this->telegram()->reset();
        $this->service(CustomerNotifier::class)->paymentSettled($payment);
        self::assertSame([self::text(BotText::ReferralCommission, ['commission' => Money::format('12000'), 'paid' => Money::format('120000'), 'balance' => Messages::balance('12000.00')])], $this->telegram()->sentTo(self::CHAT));
        self::assertCount(1, $this->telegram()->sentTo(self::FRIEND), 'the customer hears about their own payment');

        $this->telegram()->reset();
        $this->service(CustomerNotifier::class)->paymentSettled($payment);
        self::assertSame([], $this->telegram()->sentTo(self::CHAT), 'a second notice of the payment (a retry) does not tell the referrer again');

        self::assertNull($this->service(ReferralService::class)->reward($payment), 'the payment earned once');
        self::assertSame('12000.00', $this->referrer->balance());
    }

    public function testAWalletTopUpByCardEarnsButAPurchaseFromTheWalletDoesNot(): void
    {
        $this->paidByCard($this->topUpOrder($this->friend, '200000'));
        self::assertSame('20000.00', $this->referrer->balance(), 'the money came in with the top-up');

        $this->service(PaymentService::class)->createForOrder($this->purchaseOrder($this->friend, $this->plan, $this->server), $this->walletMethod());
        self::assertSame('80000.00', $this->friend->balance(), 'the friend bought from the wallet');
        self::assertSame('20000.00', $this->referrer->balance(), 'spending the wallet earns nothing more');
        self::assertSame(1, ReferralCommission::query()->count());
    }

    public function testAnAgentsTrafficPaidByCardIsMoneyInToo(): void
    {
        $agent = $this->agent(overrides: ['telegram_id' => self::FRIEND + 1, 'referred_by' => $this->referrer->id]);

        $payment = $this->paidByCard($this->service(OrderService::class)->openTraffic($agent, 20));

        self::assertSame(Money::percentOf($payment->amount, 10), $this->referrer->balance(), '10% of what the agent paid for 20 GB');
    }

    public function testOnlyTheFirstMoneyInEarnsWhenTheAdminSaysSo(): void
    {
        $this->referralProgram(firstOnly: true);
        $card = $this->cardMethod();
        // Support's gift spent from the wallet first: no money came in with it.
        $this->wallet($this->friend, $this->plan->price);
        $this->service(PaymentService::class)->createForOrder($this->purchaseOrder($this->friend, $this->plan, $this->server), $this->walletMethod());

        $this->paidByCard($this->topUpOrder($this->friend, '50000'), $card);
        $this->paidByCard($this->purchaseOrder($this->friend, $this->plan, $this->server), $card);

        self::assertSame('5000.00', $this->referrer->balance(), 'the first card payment earned, the next nothing');
        self::assertSame(1, ReferralCommission::query()->count());
    }

    public function testTheFirstPaymentIsTheFirstMoneyInWhicheverIsRewardedFirst(): void
    {
        $this->referralProgram(firstOnly: true);
        $card = $this->cardMethod();
        $referrals = $this->service(ReferralService::class);
        // Two receipts approved in the same moment: both paid before either was rewarded, the later one rewarded first.
        $first = $this->receipt($this->cardPayment($this->topUpOrder($this->friend, '50000'), $card));
        $second = $this->receipt($this->cardPayment($this->topUpOrder($this->friend, '70000'), $card));
        $this->referralProgram(enabled: false);
        $this->service(PaymentService::class)->approve($first, 'admin');
        $this->service(PaymentService::class)->approve($second, 'admin');
        $this->referralProgram(firstOnly: true);

        self::assertNull($referrals->reward($second->refresh()), 'not the first money in');
        self::assertNotNull($referrals->reward($first->refresh()));
        self::assertNull($referrals->reward($first), 'and once');
        self::assertSame('5000.00', $this->referrer->balance());

        // A commission already earned keeps any later payment out, whatever the order the rewards ran in.
        $third = $this->paidByCard($this->topUpOrder($this->friend, '90000'), $card);
        self::assertNull($referrals->reward($third));
        self::assertSame(1, ReferralCommission::query()->count());
    }

    public function testNothingIsEarnedWhileTheProgramIsOffByABannedReferrerOrForAFraction(): void
    {
        $this->referralProgram(enabled: false);
        $this->paidByCard($this->topUpOrder($this->friend, '100000'));
        self::assertSame('0.00', $this->referrer->balance(), 'the program is off');

        $this->referralProgram();
        $users = $this->service(UserActions::class);
        $users->setStatus($this->referrer, $this->panelActor(), ['status' => 'banned']);
        $this->paidByCard($this->topUpOrder($this->friend, '100000'));
        self::assertSame('0.00', $this->referrer->balance(), 'a banned referrer earns nothing');

        $users->setStatus($this->referrer, $this->panelActor(), ['status' => 'active']);
        $this->paidByCard($this->topUpOrder($this->friend, '9'));
        self::assertSame('0.00', $this->referrer->balance(), '10% of 9 Toman is less than one');
        self::assertSame(0, ReferralCommission::query()->count());
    }

    public function testACustomerNobodyBroughtEarnsNobodyAnything(): void
    {
        $stranger = $this->customer(['telegram_id' => self::FRIEND + 1]);

        $payment = $this->paidByCard($this->topUpOrder($stranger, '100000'));

        self::assertTrue($payment->isPaid());
        self::assertSame(0, ReferralCommission::query()->count());
    }

    public function testOnlyTheOneWhoBroughtThePayerEarnsNotWhoeverBroughtThem(): void
    {
        $grandchild = $this->customer(['telegram_id' => self::FRIEND + 1, 'referred_by' => $this->friend->id]);

        $this->paidByCard($this->topUpOrder($grandchild, '100000'));

        self::assertSame('10000.00', $this->friend->balance(), 'who brought the payer');
        self::assertSame('0.00', $this->referrer->balance(), 'one level only');
    }

    public function testATopUpsRefundTakesItsCommissionBackOutOfTheReferrersWalletAndTellsThem(): void
    {
        $purchase = $this->paidByCard($this->purchaseOrder($this->friend, $this->plan, $this->server));
        $topUp = $this->paidByCard($this->topUpOrder($this->friend, '100000'));
        self::assertSame('22000.00', $this->referrer->balance());

        $this->telegram()->reset();
        $this->service(PaymentActions::class)->refund($topUp, $this->panelActor(), 'درخواست مشتری');

        self::assertSame(['12000.00', '0.00'], [$this->referrer->balance(), $this->friend->balance()], "its money went back out of the shop, and the top-up's 10,000 with it");
        $line = WalletTransaction::query()->where('user_id', $this->referrer->id)->latest('id')->firstOrFail();
        self::assertSame([sprintf(ReferralService::LINE_TAKEN_BACK, $topUp->id), '10000.00'], [$line->description, $line->amount], "its own line, without support's note to the customer");
        self::assertFalse(ReferralCommission::query()->where('payment_id', $topUp->id)->exists(), 'no longer one of their earnings');
        self::assertSame('12000.00', $this->service(ReferralService::class)->statsFor($this->referrer)['earned']);
        self::assertSame([self::text(BotText::ReferralCommissionTakenBack, ['commission' => Money::format('10000'), 'balance' => Messages::balance('12000.00')])], $this->telegram()->sentTo(self::CHAT), 'told as of a commission');
        self::assertTrue(ReferralCommission::query()->where('payment_id', $purchase->id)->exists(), "the purchase's stands");
    }

    public function testAPurchasesRefundLeavesItsCommissionAsItsMoneyStaysInTheShopEvenOnceSpent(): void
    {
        $this->referralProgram(firstOnly: true);
        $purchase = $this->paidByCard($this->purchaseOrder($this->friend, $this->plan, $this->server));
        $this->service(WalletService::class)->debit($this->referrer, '5000', 'خرید');

        $this->telegram()->reset();
        $this->service(PaymentActions::class)->refund($purchase, $this->panelActor(), 'درخواست مشتری');

        self::assertSame([PaymentStatus::Refunded, '120000.00'], [$purchase->refresh()->status, $this->friend->balance()], "refunded to the customer's wallet: the money is still the shop's");
        self::assertSame(['7000.00', 1], [$this->referrer->balance(), ReferralCommission::query()->where('payment_id', $purchase->id)->count()], 'the commission stands, spent or not');
        self::assertSame([], $this->telegram()->sentTo(self::CHAT), 'the referrer is told nothing');

        $this->paidByCard($this->topUpOrder($this->friend, '50000'));
        self::assertSame('7000.00', $this->referrer->balance(), 'the commission it earned keeps the next payment from being the first');
    }

    public function testATopUpsRefundIsRefusedWhileTheReferrerHasSpentItsCommissionAndNothingIsTakenBack(): void
    {
        $topUp = $this->paidByCard($this->topUpOrder($this->friend, '100000'));
        $this->service(WalletService::class)->debit($this->referrer, '5000', 'خرید');

        foreach ([
            'from the panels' => fn() => $this->service(PaymentActions::class)->refund($topUp, $this->panelActor(), null),
            'at the bottom' => fn() => $this->service(PaymentService::class)->refund($topUp, 'admin', null),
        ] as $way => $refund) {
            try {
                $refund();
                self::fail("refused {$way}");
            } catch (RefundRefusedException $e) {
                self::assertSame(RefundRefusedException::commissionSpent('5000.00')->getMessage(), $e->getMessage(), $way);
            }
        }

        self::assertSame([PaymentStatus::Paid, '5000.00', 1], [$topUp->refresh()->status, $this->referrer->balance(), ReferralCommission::query()->count()], 'nothing refunded, nothing taken back');
        self::assertSame('100000.00', $this->friend->balance(), 'the top-up stays in their wallet');
    }

    public function testAnAgentReferrersCreditCoversTheCommissionTakenBack(): void
    {
        $agent = $this->agent(credit: '50000', overrides: ['telegram_id' => self::FRIEND + 5]);
        $friend = $this->customer(['telegram_id' => self::FRIEND + 6, 'referred_by' => $agent->id]);
        $topUp = $this->paidByCard($this->topUpOrder($friend, '100000'));
        $this->service(WalletService::class)->debit($agent, '10000', 'خرید');

        self::assertTrue($this->service(PaymentService::class)->refund($topUp, 'admin', null));
        self::assertSame('-10000.00', $agent->balance(), 'below zero within their credit, as their own top-up would');
    }

    public function testAPaymentRefundedBeforeItsCommissionWasCreditedEarnsNothing(): void
    {
        $this->referralProgram(enabled: false);
        $topUp = $this->paidByCard($this->topUpOrder($this->friend, '100000'));
        $readBefore = Payment::query()->findOrFail($topUp->id);
        self::assertTrue($this->service(PaymentService::class)->refund($topUp, 'admin', null));
        $this->referralProgram();

        self::assertTrue($readBefore->isPaid());
        self::assertNull($this->service(ReferralService::class)->reward($readBefore), 'the copy read before the refund still says paid; the row, under its lock, does not');
        self::assertSame('0.00', $this->referrer->balance());
    }

    public function testATopUpRefundedWithItsCommissionLeavesTheNextPaymentTheFirst(): void
    {
        $this->referralProgram(firstOnly: true);
        $topUp = $this->paidByCard($this->topUpOrder($this->friend, '100000'));
        self::assertTrue($this->service(PaymentService::class)->refund($topUp, 'admin', null));

        $this->paidByCard($this->topUpOrder($this->friend, '50000'));

        self::assertSame('5000.00', $this->referrer->balance(), 'its money went back out of the shop: it was no first money in');
    }

    public function testACommissionThatFailsIsLoggedAndNeverStandsInThePaymentsWay(): void
    {
        $logs = $this->logs();
        $payment = $this->whileListening(
            'eloquent.creating: ' . ReferralCommission::class,
            static fn(): never => throw new \RuntimeException('the referral table is out of reach'),
            fn(): Payment => $this->paidByCard($this->purchaseOrder($this->friend, $this->plan, $this->server)),
        );

        self::assertTrue($logs->hasErrorThatContains("The referral commission of payment {$payment->id} failed"));
        self::assertTrue($payment->isPaid());
        self::assertNotNull($payment->order->subscription, 'delivered all the same');
        self::assertSame([0, '0.00'], [ReferralCommission::query()->count(), $this->referrer->balance()], 'and nothing half-earned');
    }
}
