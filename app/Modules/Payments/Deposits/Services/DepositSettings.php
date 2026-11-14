<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits\Services;

use App\Core\Security\Encrypter;
use App\Modules\Payments\Models\Payment;
use App\Modules\Settings\Services\Settings;
use App\Support\Money;

/**
 * «تایید خودکار واریز», each shop its own: whether the bank SMS a phone app forwards approve card payments (and every
 * card payment asks a sum of its own: Payments\Services\PaymentCards), and the key the app sends with each — made once,
 * kept encrypted, made anew on request (the old one stops working at once).
 */
final class DepositSettings
{
    private const ENABLED = 'deposits.enabled';
    private const KEY = 'deposits.key';

    /** A key's length: 32 random bytes, hex. */
    private const KEY_BYTES = 32;

    public function __construct(
        private readonly Settings $settings,
        private readonly Encrypter $encrypter,
    ) {}

    /** Whether the current shop listens to its deposits. */
    public function enabled(): bool
    {
        return $this->settings->get(self::ENABLED, false) === true;
    }

    /**
     * Whether the card payment's own bank SMS approves it, as the customer is told: the shop listens, and the payment asks
     * a sum above its order's — of its own (Payments\Services\PaymentCards; none when the method's most leaves no room).
     */
    public function approvesItself(Payment $payment): bool
    {
        return $this->enabled() && Money::compare($payment->amount, $payment->order->amount) > 0;
    }

    public function enable(bool $on): void
    {
        $this->settings->set(self::ENABLED, $on);
        if ($on) {
            $this->key();
        }
    }

    /** The current shop's key — made now when it has none. */
    public function key(): string
    {
        $kept = $this->settings->get(self::KEY);
        if (is_string($kept) && $kept !== '') {
            try {
                return $this->encrypter->decrypt($kept);
            } catch (\Throwable) {
                // Kept under another APP_KEY: no app can know it any more — a new one, below.
            }
        }

        return $this->rotate();
    }

    /** A new key for the current shop: the app must be given it, the old one no longer opens the webhook. */
    public function rotate(): string
    {
        $key = bin2hex(random_bytes(self::KEY_BYTES));
        $this->settings->set(self::KEY, $this->encrypter->encrypt($key));

        return $key;
    }

    /** Whether `$given` is the current shop's key (in constant time); never while it has none. */
    public function accepts(string $given): bool
    {
        $kept = $this->settings->get(self::KEY);

        return is_string($kept) && $kept !== '' && $given !== '' && hash_equals($this->key(), $given);
    }
}
