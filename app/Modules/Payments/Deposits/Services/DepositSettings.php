<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits\Services;

use App\Core\Security\Encrypter;
use App\Modules\Bots\CurrentBot;
use App\Modules\Payments\Models\Payment;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Services\Settings;
use App\Support\Money;
use Psr\Log\LoggerInterface;

/**
 * «تایید خودکار واریز», each shop its own: whether the bank SMS a phone app forwards approve card payments (and every
 * card payment asks a sum of its own: Payments\Services\PaymentCards), and the key the app sends with each — made once,
 * kept encrypted, made anew on request (the old one stops working at once). Rikhtam's side has a key of its own: its
 * gateway's «کلید امنیتی», which Rikhtam makes and the owner pastes, kept encrypted beside what Rikhtam said when the
 * shop last registered its address with it (RikhtamDeposits).
 */
final class DepositSettings
{
    private const ENABLED = 'deposits.enabled';
    private const KEY = 'deposits.key';
    private const RIKHTAM_KEY = 'deposits.rikhtam_key';
    private const RIKHTAM_HOOK = 'deposits.rikhtam_hook';

    /** A key's length: 32 random bytes, hex. */
    private const KEY_BYTES = 32;

    public function __construct(
        private readonly Settings $settings,
        private readonly Encrypter $encrypter,
        private readonly LoggerInterface $logger,
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

    /** The current shop's key — made now when it has none, or when the one kept no longer decrypts (logged). */
    public function key(): string
    {
        $kept = $this->settings->get(self::KEY);
        if (is_string($kept) && $kept !== '') {
            try {
                return $this->encrypter->decrypt($kept);
            } catch (\Throwable $e) {
                // Kept under another APP_KEY: no app can know it any more — a new one, below, which the app must be given.
                $this->logger->warning('The deposits key of shop {shop} cannot be decrypted (was APP_KEY changed?), so a new one is made: the phone app must be given it, or its SMS are refused: {message}', [
                    'shop' => CurrentBot::id(),
                    'message' => $e->getMessage(),
                ]);
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

    /** The current shop's Rikhtam key; null for none — or one kept under another APP_KEY, which must be pasted again. */
    public function rikhtamKey(): ?string
    {
        $kept = $this->settings->get(self::RIKHTAM_KEY);
        if (!is_string($kept) || $kept === '') {
            return null;
        }
        try {
            $key = $this->encrypter->decrypt($kept);
        } catch (\Throwable) {
            return null;
        }

        return $key === '' ? null : $key;
    }

    /** Whether the current shop hears Rikhtam's deposits: it has a key from Rikhtam. */
    public function rikhtamConnected(): bool
    {
        return $this->rikhtamKey() !== null;
    }

    /** Whether `$given` is the current shop's Rikhtam key (in constant time); never while it has none. */
    public function acceptsRikhtam(string $given): bool
    {
        $key = $this->rikhtamKey();

        return $key !== null && $given !== '' && hash_equals($key, $given);
    }

    /**
     * Whether another shop of the site keeps `$key` as its Rikhtam key: a Rikhtam gateway sends to the one address
     * registered last, so a second shop registering it would take the first one's deposits.
     */
    public function rikhtamKeyElsewhere(string $key): bool
    {
        $kept = Setting::query()->where('key', self::RIKHTAM_KEY)->where('bot_id', '!=', CurrentBot::id())->pluck('value');
        foreach ($kept as $value) {
            $encrypted = is_string($value) ? json_decode($value, true) : null;
            try {
                if (is_string($encrypted) && $encrypted !== '' && hash_equals($this->encrypter->decrypt($encrypted), $key)) {
                    return true;
                }
            } catch (\Throwable) {
                // Kept under another APP_KEY: that shop no longer hears it.
            }
        }

        return false;
    }

    /**
     * What Rikhtam said when the shop last registered its address: the domain its gateway is for, the gateway's id at
     * Rikhtam, the address it took, and when; null for none.
     *
     * @return array{domain: string|null, project_id: int|null, url: string, at: string}|null
     */
    public function rikhtamHook(): ?array
    {
        $hook = $this->settings->get(self::RIKHTAM_HOOK);
        if (!is_array($hook) || !is_string($hook['url'] ?? null) || !is_string($hook['at'] ?? null)) {
            return null;
        }

        return [
            'domain' => is_string($hook['domain'] ?? null) ? $hook['domain'] : null,
            'project_id' => is_int($hook['project_id'] ?? null) ? $hook['project_id'] : null,
            'url' => $hook['url'],
            'at' => $hook['at'],
        ];
    }

    /**
     * Keeps `$key` as the current shop's Rikhtam key (encrypted), and what Rikhtam answered when `$url` was registered
     * with it (`domain`, `project_id`, `url`).
     *
     * @param array<string, mixed> $answer
     */
    public function connectRikhtam(string $key, string $url, array $answer): void
    {
        $this->settings->set(self::RIKHTAM_KEY, $this->encrypter->encrypt($key));
        $project = $answer['project_id'] ?? null;
        $this->settings->set(self::RIKHTAM_HOOK, [
            'domain' => is_string($answer['domain'] ?? null) && $answer['domain'] !== '' ? mb_substr($answer['domain'], 0, 255) : null,
            'project_id' => is_int($project) || (is_string($project) && ctype_digit($project)) ? (int) $project : null,
            'url' => is_string($answer['url'] ?? null) && $answer['url'] !== '' ? mb_substr($answer['url'], 0, 500) : $url,
            'at' => now()->toIso8601String(),
        ]);
    }

    /** Forgets the current shop's Rikhtam key and what its registration said: a deposit signed with it is refused from now on. */
    public function forgetRikhtam(): void
    {
        $this->settings->forget(self::RIKHTAM_KEY);
        $this->settings->forget(self::RIKHTAM_HOOK);
    }
}
