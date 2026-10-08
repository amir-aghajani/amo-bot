<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
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
 * What a referred customer's payment earns the one who brought them: the admin's rate of money that came in — a card
 * payment of a purchase, a top-up or an agent's traffic, never a purchase from the wallet — credited to the referrer's
 * wallet once per payment, told to them once, and only for the first money in when the admin says so. One level only;
 * a refund leaves it standing; a commission that fails never stands in the payment's way.
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

    public function testARefundLeavesTheCommissionStanding(): void
    {
        $purchase = $this->paidByCard($this->purchaseOrder($this->friend, $this->plan, $this->server));
        $topUp = $this->paidByCard($this->topUpOrder($this->friend, '100000'));

        self::assertTrue($this->service(PaymentService::class)->refund($purchase, 'admin', null), 'its money stays in the shop, as the customer\'s balance');
        self::assertTrue($this->service(PaymentService::class)->refund($topUp, 'admin', null), 'given back outside the shop — the shop\'s call');

        self::assertSame([PaymentStatus::Refunded, PaymentStatus::Refunded], [$purchase->refresh()->status, $topUp->refresh()->status]);
        self::assertSame('22000.00', $this->referrer->balance(), 'earned for bringing a customer who paid');
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
