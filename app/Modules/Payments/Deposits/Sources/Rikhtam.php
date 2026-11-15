<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits\Sources;

use App\Core\Exceptions\ValidationException;
use App\Modules\Payments\Deposits\Contracts\DepositSource;
use App\Modules\Payments\Deposits\DTO\IncomingDeposit;
use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * Rikhtam (rikhtam.com, «ریختم»): its app on the shop's phone reads the bank's deposit SMS and posts each to the address
 * the shop registered for its gateway (Payments\Controllers\RikhtamWebhookController, the key in the body) — a form:
 * `uid` (its own id for the deposit), `amount` and `amount_rial` (Rial), `account` (the account it came into; may be
 * empty), `sms_time` (Unix seconds) and `time` (Tehran's clock, as text), `ts`, `event_id`, `project_id`, and `test=1` on
 * its panel's health test alone — `invoice` (and `manual=1`) when the owner attaches it to an invoice by hand. Only money
 * that came in is sent, and no SMS text: what is kept to read is made of what came (body()).
 */
final class Rikhtam implements DepositSource
{
    public const KEY = 'rikhtam';

    /** Who sent it, as support reads it beside the deposit (it has no bank's name). */
    private const SENDER = 'ریختم';

    /** The longest deposit id (`uid`) taken. */
    public const UID_MAX = 100;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ریختم';
    }

    public function read(array $body): IncomingDeposit
    {
        $uid = $body['uid'] ?? null;
        if (!is_string($uid) || trim($uid) === '' || mb_strlen($uid) > self::UID_MAX) {
            throw ValidationException::on('uid', 'شناسه واریز (uid) نیامده است.');
        }
        $amount = self::digits($body['amount'] ?? null) ?? self::digits($body['amount_rial'] ?? null);
        if ($amount === null && ($body['amount'] ?? $body['amount_rial'] ?? '') !== '') {
            throw ValidationException::on('amount', 'مبلغ (amount) باید عدد ریال باشد.');
        }

        return new IncomingDeposit(
            externalId: trim($uid),
            amountRial: $amount,
            isDeposit: true,
            test: ($body['test'] ?? null) === '1',
            receivedAt: self::when($body),
            bank: null,
            sender: self::SENDER,
            body: self::body($amount, self::account($body), $body['time'] ?? null),
        );
    }

    /**
     * The invoice the owner attached the deposit to by hand: the shop's payment number as they typed it, in `invoice` —
     * which only an attach carries; `manual=1` beside it is not required, Rikhtam's own sample code reading `invoice`
     * alone —; null for a deposit sent as it came.
     *
     * @param array<string, mixed> $body
     */
    public static function attachedTo(array $body): ?string
    {
        $invoice = $body['invoice'] ?? null;

        return is_string($invoice) && trim($invoice) !== '' ? trim($invoice) : null;
    }

    /**
     * The last four digits of the account it came into; null when Rikhtam sent none.
     *
     * @param array<string, mixed> $body
     */
    private static function account(array $body): ?string
    {
        $digits = preg_replace('/\D+/', '', Persian::latinDigits(is_string($body['account'] ?? null) ? $body['account'] : ''));

        return $digits === null || $digits === '' ? null : substr($digits, -4);
    }

    /**
     * When the phone got it: `sms_time`, else `ts`, else now.
     *
     * @param array<string, mixed> $body
     */
    private static function when(array $body): Carbon
    {
        foreach (['sms_time', 'ts'] as $field) {
            $seconds = self::digits($body[$field] ?? null);
            if ($seconds !== null && $seconds > 0) {
                return Carbon::createFromTimestamp($seconds)->utc();
            }
        }

        return now()->utc();
    }

    /** A line for support from what came, in place of the SMS Rikhtam does not send: the sum, the account, the time. */
    private static function body(?int $amount, ?string $account, mixed $time): string
    {
        return implode(' · ', array_filter([
            $amount === null ? 'واریز بدون مبلغ' : 'واریز ' . Persian::number($amount) . ' ریال',
            $account === null ? null : "به حساب \u{200E}…{$account}",
            is_string($time) && trim($time) !== '' ? mb_substr(trim($time), 0, 40) : null,
        ]));
    }

    /** A whole number sent as digits (or an integer); null for anything else. */
    private static function digits(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        return is_string($value) && $value !== '' && strlen($value) <= 18 && ctype_digit($value) ? (int) $value : null;
    }
}
