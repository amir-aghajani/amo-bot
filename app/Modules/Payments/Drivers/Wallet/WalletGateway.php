<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Wallet;

use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\DTO\PaymentInitiation;
use App\Modules\Payments\DTO\PaymentResult;
use App\Modules\Payments\Models\Payment;
use App\Modules\Users\Exceptions\InsufficientBalanceException;
use App\Modules\Users\Services\WalletService;

/**
 * The wallet's gateway (made by WalletDriver): it pays an order from the customer's pre-loaded balance — an agent's
 * down to their credit below zero — and settles at once. The shop's code names the wallet by its key and its label here.
 */
final class WalletGateway implements GatewayInterface
{
    public function __construct(private readonly WalletService $wallet) {}

    /** The key every shop's wallet row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'wallet';
    }

    /** The driver's name, and the built-in row's label. */
    public static function label(): string
    {
        return 'کیف پول';
    }

    public function initiate(): PaymentInitiation
    {
        return PaymentInitiation::instant();
    }

    /** The debit is the payment — down to an agent's credit —, and its ledger line the gateway's own id of it. */
    public function settle(Payment $payment): PaymentResult
    {
        $customer = $payment->order->user;
        try {
            $line = $this->wallet->debit($customer, $payment->amount, WalletService::describePayment($payment), $customer->credit());
        } catch (InsufficientBalanceException $e) {
            return PaymentResult::failure($e->getMessage());
        }

        return PaymentResult::success('wallet-tx-' . $line->id);
    }
}
