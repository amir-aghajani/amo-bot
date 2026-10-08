<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\RefundRefusedException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Enums\WalletTransactionType;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\WalletService;
use App\Support\Money;
use App\Support\Traffic;
use Tests\HttpTestCase;

/**
 * Refunds from the payments screen, by what the order bought: a purchase or a renewal goes to the customer's wallet and
 * what it delivered stays; an agent's traffic goes to their wallet and its GB come back out of their pool — refused
 * once sold; a wallet top-up is given back outside the shop, so it comes back out of the wallet — refused once spent,
 * an agent's credit counting. What was never delivered has nothing to take back. A failed delivery is refunded and its
 * order closed; one under way is not refunded under it. The customer hears it, as a reply to their receipt. Support's
 * note is held to the room of the wallet line it is written on.
 */
final class PaymentRefundsTest extends HttpTestCase
{
    private User $ali;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->loginAsAdmin();

        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali_r']);
        $this->card = $this->cardMethod();
    }

    public function testAPurchaseIsRefundedToTheWalletAndItsServiceStays(): void
    {
        $server = $this->sellingServer('Berlin');
        $payment = $this->paidByCard($this->purchaseOrder($this->ali, $this->plan(['price' => '50000.00'], $server), $server), $this->card);
        $this->telegram()->reset();

        $refunded = $this->postJson("/api/admin/payments/{$payment->id}/refund", ['note' => 'اشتباه واریز شده بود']);

        self::assertSame(200, $refunded->getStatusCode(), (string) $refunded->getBody());
        $row = $this->decode($refunded)['payment'];
        self::assertSame(['refunded', 'refunded', 'اشتباه واریز شده بود'], [$row['status'], $row['order']['status'], $row['refund_note']]);
        self::assertSame('50000.00', $this->ali->balance(), 'the amount went to the wallet');
        self::assertSame(SubscriptionStatus::Active, $payment->order->subscription?->status, 'the service stays — switching it off is the services screen\'s');
        self::assertSame(WalletService::describeRefund($payment, 'اشتباه واریز شده بود'), $this->lastLine()->description);
        self::assertSame([self::text(BotText::PaymentRefunded, [
            'amount' => Money::format('50000'),
            'order' => $payment->order_id,
            'note' => self::text(BotText::AdminNote, ['comment' => 'اشتباه واریز شده بود']),
            'balance' => Messages::balance('50000.00'),
        ])], $this->telegram()->sentTo(1001));
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0), 'a reply to the receipt');
        self::assertArrayNotHasKey('reply_markup', $this->telegram()->params(0), 'no keyboard on a verdict');

        self::assertSame(422, $this->postJson("/api/admin/payments/{$payment->id}/refund", [])->getStatusCode(), 'once');
        self::assertSame(422, $this->postJson("/api/admin/payments/{$payment->id}/retry")->getStatusCode(), 'nothing failed');
    }

    public function testARenewalIsRefundedToTheWalletAndWhatItGaveTheServiceStays(): void
    {
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00', 'duration_days' => 30], $server);
        $subscription = $this->buy($this->ali, $plan, $server)->subscription ?? self::fail('nothing delivered');
        $renewal = $this->paidByCard($this->service(OrderService::class)->createRenewal($this->ali, $subscription, $plan), $this->card);
        self::assertSame([OrderStatus::Fulfilled, 60], [$renewal->order->status, $subscription->refresh()->duration_days], 'renewed: a term not started yet runs longer');

        $row = $this->decode($this->postJson("/api/admin/payments/{$renewal->id}/refund", []))['payment'];

        self::assertSame(['refunded', 'refunded'], [$row['status'], $row['order']['status']]);
        self::assertSame('50000.00', $this->ali->balance(), 'the amount went to the wallet');
        self::assertSame([SubscriptionStatus::Active, 60], [$subscription->refresh()->status, $subscription->duration_days], 'the renewed term stays');
    }

    public function testATopUpRefundTakesTheTopUpBackOutOfTheWalletWhileItIsThere(): void
    {
        $payment = $this->paidByCard($this->topUpOrder($this->ali, '50000.00'), $this->card);
        $spent = $this->paidByCard($this->topUpOrder($this->ali, '30000.00'), $this->card);
        $this->service(WalletService::class)->debit($this->ali, '60000', 'خرید');
        $this->telegram()->reset();

        // 20,000 left: the 30,000 top-up comes back out — but not the 50,000 one, spent since.
        $refused = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(RefundRefusedException::topUpSpent('20000.00')->getMessage(), $this->decode($refused)['message']);
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status, 'nothing moved');
        self::assertSame([], $this->telegram()->calls());

        $this->service(WalletService::class)->credit($this->ali, '10000', 'هدیه');
        $refunded = $this->postJson("/api/admin/payments/{$spent->id}/refund", ['note' => 'برگشت به کارت']);
        self::assertSame(200, $refunded->getStatusCode(), (string) $refunded->getBody());
        self::assertSame('0.00', $this->ali->balance(), 'the money went back to the card, outside the shop: the wallet gives the top-up back');
        $line = $this->lastLine();
        self::assertSame([WalletTransactionType::Debit, '30000.00', WalletService::describeTopUpRefund($spent, 'برگشت به کارت')], [$line->type, $line->amount, $line->description]);
        self::assertSame([self::text(BotText::TopupRefunded, [
            'amount' => Money::format('30000'),
            'order' => $spent->order_id,
            'note' => self::text(BotText::AdminNote, ['comment' => 'برگشت به کارت']),
            'balance' => Messages::balance('0.00'),
        ])], $this->telegram()->sentTo(1001));
    }

    public function testAnAgentsTopUpIsTakenBackAsFarAsTheirCreditGoes(): void
    {
        $agent = $this->agent(credit: '100000', overrides: ['telegram_id' => 7007]);
        $payment = $this->paidByCard($this->topUpOrder($agent, '50000.00'), $this->card);
        $this->service(WalletService::class)->debit($agent, '80000', 'خرید', $agent->credit());

        $response = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('-80000.00', $agent->balance(), 'spent, and the top-up still came back out — into their credit');
    }

    public function testAnAgentsTrafficRefundTakesItsTrafficBackWhileTheBotHasIt(): void
    {
        $agent = $this->agent(overrides: ['telegram_id' => 7007]);
        $bot = $this->agentBot($agent, traffic: 0);
        $order = $this->service(OrderService::class)->openTraffic($agent, 20);
        $payment = $this->paidByCard($order, $this->card);
        self::assertSame(Traffic::bytesOfGb(20), $bot->trafficBalance());
        $this->traffic($bot, 15);

        $sold = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);
        self::assertSame(422, $sold->getStatusCode(), 'some of it was sold since');
        self::assertSame(RefundRefusedException::trafficSold(Traffic::bytesOfGb(15))->getMessage(), $this->decode($sold)['message']);

        $this->traffic($bot, 25);
        self::assertSame(200, $this->postJson("/api/admin/payments/{$payment->id}/refund", [])->getStatusCode());
        self::assertSame(Traffic::bytesOfGb(5), $bot->trafficBalance(), 'its 20 GB came back out');
        self::assertSame($order->amount, $agent->balance(), 'and the money went to their wallet');
    }

    public function testATopUpOrTrafficNeverDeliveredHasNothingToTakeBack(): void
    {
        $topUp = $this->paidUndelivered($this->service(OrderService::class)->openTopUp($this->ali, 50000));

        $row = $this->decode($this->postJson("/api/admin/payments/{$topUp->id}/refund", []))['payment'];
        self::assertSame(['refunded', 'refunded'], [$row['status'], $row['order']['status']]);
        self::assertSame('0.00', $this->ali->balance(), 'nothing landed in the wallet, and nothing comes out of it');
        self::assertSame([self::text(BotText::TopupRefundedUndelivered, [
            'amount' => Money::format('50000'),
            'order' => $topUp->order_id,
            'note' => '',
        ])], $this->telegram()->sentTo(1001), 'and the customer is told so — not that it came out of their wallet');

        $agent = $this->agent(overrides: ['telegram_id' => 7007]);
        $bot = $this->traffic($this->agentBot($agent, traffic: 0), 25);
        $traffic = $this->paidUndelivered($this->service(OrderService::class)->openTraffic($agent, 20));

        self::assertSame(200, $this->postJson("/api/admin/payments/{$traffic->id}/refund", [])->getStatusCode());
        self::assertSame(Traffic::bytesOfGb(25), $bot->trafficBalance(), 'nothing was added to the pool, nothing comes out of it');
        self::assertSame($traffic->amount, $agent->balance(), 'the money goes to their wallet');
    }

    public function testRefundingAFailedDeliveryClosesTheOrderAndOneUnderWayWaits(): void
    {
        $server = $this->sellingServer('Berlin', ['is_active' => false]);
        $payment = $this->paidByCard($this->purchaseOrder($this->ali, $this->plan(['price' => '50000.00'], $server), $server), $this->card);
        self::assertSame(OrderStatus::Failed, $payment->order->status);

        // A retry claimed it for delivery: the money is not given back under it.
        Order::query()->whereKey($payment->order_id)->update(['status' => OrderStatus::Processing->value]);
        self::assertFalse($this->decode($this->get("/api/admin/payments/{$payment->id}"))['payment']['actions']['refund']);
        self::assertSame(422, $this->postJson("/api/admin/payments/{$payment->id}/refund", [])->getStatusCode());
        self::assertSame([PaymentStatus::Paid, '0.00'], [$payment->refresh()->status, $this->ali->balance()]);

        // That delivery failed too.
        Order::query()->whereKey($payment->order_id)->update(['status' => OrderStatus::Failed->value]);
        $stuck = fn(): int => $this->decode($this->get('/api/admin/queues'))['queues']['stuck_orders'];
        self::assertSame(1, $stuck(), 'support may deliver it again');
        $row = $this->decode($this->postJson("/api/admin/payments/{$payment->id}/refund", []))['payment'];
        self::assertSame(['refunded', 'refunded'], [$row['status'], $row['order']['status']], 'the order is closed: its retry goes with the money');
        self::assertFalse($row['actions']['retry']);
        self::assertSame(0, $stuck(), 'nothing is owed any more: out of the queue with it');
        self::assertSame('50000.00', $this->ali->balance());
    }

    public function testARefundsNoteIsHeldToTheWalletLineItIsWrittenOn(): void
    {
        $server = $this->sellingServer('Berlin');
        $payment = $this->paidByCard($this->purchaseOrder($this->ali, $this->plan(['price' => '50000.00'], $server), $server), $this->card);

        $long = $this->postJson("/api/admin/payments/{$payment->id}/refund", ['note' => str_repeat('ی', PaymentService::REFUND_NOTE_MAX + 1)]);
        self::assertSame(422, $long->getStatusCode());
        self::assertArrayHasKey('note', $this->decode($long)['errors']);
        self::assertSame([PaymentStatus::Paid, '0.00'], [$payment->refresh()->status, $this->ali->balance()], 'refused before anything moved');

        $note = str_repeat('ی', PaymentService::REFUND_NOTE_MAX);
        self::assertSame(200, $this->postJson("/api/admin/payments/{$payment->id}/refund", ['note' => $note])->getStatusCode());
        $line = (string) $this->lastLine()->description;
        self::assertSame(WalletService::describeRefund($payment, $note), $line);
        // wallet_transactions.description is a string of 255 (database/schema.php): MySQL refuses a longer one.
        self::assertLessThanOrEqual(255, mb_strlen($line), 'the longest note leaves the line within its column');
    }

    /** The order paid by card, and the process that was to deliver it gone before it started. */
    private function paidUndelivered(Order $order): Payment
    {
        self::assertTrue($this->service(OrderService::class)->markPaid($order));

        return $this->cardPayment($order, $this->card, ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
    }

    private function lastLine(): WalletTransaction
    {
        return WalletTransaction::query()->where('user_id', $this->ali->id)->latest('id')->firstOrFail();
    }
}
