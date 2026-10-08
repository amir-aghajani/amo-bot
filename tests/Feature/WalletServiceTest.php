<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Users\Exceptions\InsufficientBalanceException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\WalletService;
use Tests\DatabaseTestCase;

/**
 * The wallet's ledger, its one writer: every move a line carrying the balance from then on — the last line's is the
 * balance, read with a list's rows too —; a debit no further below zero than the credit its caller passes (an agent's),
 * a credit always landing; and the ledger lines worded in one place.
 */
final class WalletServiceTest extends DatabaseTestCase
{
    public function testCreditAndDebitKeepBalanceAndLedgerInSync(): void
    {
        $wallet = $this->service(WalletService::class);
        $user = $this->customer();

        $wallet->credit($user, 25, 'Top-up');
        $wallet->debit($user, '۱۰٫۵', 'Order #1');

        self::assertSame('14.50', $user->balance());
        $lines = WalletTransaction::query()->where('user_id', $user->id)->oldest('id')->get();
        self::assertSame(['25.00', '10.50'], $lines->pluck('amount')->all(), 'amounts are stored normalised, whatever they came as');
        self::assertSame(['25.00', '14.50'], $lines->pluck('balance_after')->all());
    }

    public function testTheBalanceIsTheLedgersLastLineReadWithTheRowsOfAList(): void
    {
        $ali = $this->wallet($this->customer(), '12000.00');
        $sara = $this->customer(['telegram_id' => 2002]);
        $this->service(WalletService::class)->debit($ali, '2000', 'Order #1');

        self::assertSame(['10000.00', '0.00'], [$ali->balance(), $sara->balance()], 'none before the first line');
        $rows = User::query()->addSelect(User::balanceColumn())->oldest('id')->get();
        self::assertSame(['10000.00', '0.00'], $rows->map(static fn(User $user): string => $user->balance())->all());

        $agent = $this->wallet($this->agent(overrides: ['telegram_id' => 3003]), '5000.00');
        self::assertSame('5000.00', CurrentBot::run($agent->ownBot ?? self::fail('no shop'), static fn(): string => $agent->balance()), "an agent's wallet, read in their own bot's panel");
    }

    public function testLedgerLinesAreWordedInOnePlace(): void
    {
        $user = $this->customer();
        $order = $this->purchaseOrder($user, $this->plan(['price' => '5.00']), $this->fakeServer());
        $payment = $this->cardPayment($order, $this->cardMethod());
        $topUp = $this->topUpOrder($user, '5.00');

        self::assertSame("شارژ کیف پول (سفارش #{$topUp->id})", WalletService::describeTopUp($topUp));
        self::assertSame("پرداخت سفارش #{$order->id} (خرید)", WalletService::describePayment($payment));
        self::assertSame("بازگشت وجه پرداخت #{$payment->id}", WalletService::describeRefund($payment, null));
        self::assertSame("بازگشت وجه پرداخت #{$payment->id} — اشتباه", WalletService::describeRefund($payment, 'اشتباه'));
        self::assertSame("برگشت شارژ کیف پول (پرداخت #{$payment->id}) — به کارت", WalletService::describeTopUpRefund($payment, 'به کارت'));
        self::assertSame("پورسانت زیرمجموعه (پرداخت #{$payment->id})", WalletService::describeReferral($payment));
    }

    public function testDebitBeyondBalanceIsRejected(): void
    {
        $wallet = $this->service(WalletService::class);
        $user = $this->customer();

        $this->expectException(InsufficientBalanceException::class);
        $wallet->debit($user, 1, 'Nope');
    }

    public function testADebitGoesBelowZeroAsFarAsTheCreditAllowsAndACreditAlwaysLands(): void
    {
        $wallet = $this->service(WalletService::class);
        $user = $this->wallet($this->customer(), '10000.00');

        $wallet->debit($user, '50000', 'Order #1', credit: '40000');
        self::assertSame('-40000.00', $user->balance(), "down to the agent's credit");

        try {
            $wallet->debit($user, '1', 'Order #2', credit: '40000');
            self::fail('not a Toman past the credit');
        } catch (InsufficientBalanceException) {
        }

        $wallet->credit($user, '15000', 'Top-up');
        self::assertSame('-25000.00', $user->balance(), 'a credit pays a debt off even while it stays below zero');
        self::assertSame(['10000.00', '-40000.00', '-25000.00'], WalletTransaction::query()->where('user_id', $user->id)->oldest('id')->pluck('balance_after')->all());
    }

    public function testNonPositiveAmountsAreRejected(): void
    {
        $wallet = $this->service(WalletService::class);
        $user = $this->customer();

        $this->expectException(\InvalidArgumentException::class);
        $wallet->credit($user, 0, 'Zero');
    }
}
