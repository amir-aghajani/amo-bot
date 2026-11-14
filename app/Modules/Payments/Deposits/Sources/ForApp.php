<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits\Sources;

use App\Core\Exceptions\ValidationException;
use App\Modules\Payments\Deposits\Contracts\DepositSource;
use App\Modules\Payments\Deposits\DTO\IncomingDeposit;
use Illuminate\Support\Carbon;

/**
 * ForApp (github.com/erfjab/forapp), the open-source Android app that reads the bank's SMS on the shop's phone and posts
 * each to a webhook — by default JSON with `X-API-Key` and `Idempotency-Key` headers: `id` (its own id for the SMS),
 * `test`, `sender`, `bank`, `body` (the SMS as it came), `amount` (the sum it read, in Rial, or null), `unit` ("rial"),
 * `is_deposit` (true, false, or null when it could not tell), `received_at` (ISO 8601) and `received_at_ms`, `attempt`,
 * `device`. It retries an answer of 408, 425, 429 or 5xx (and no answer) and gives up on any other 4xx.
 */
final class ForApp implements DepositSource
{
    public const KEY = 'forapp';

    private const ID_MAX = 100;
    private const LABEL_MAX = 60;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'فوراپ (ForApp)';
    }

    public function read(array $body): IncomingDeposit
    {
        $id = $body['id'] ?? null;
        if (!is_string($id) || trim($id) === '' || mb_strlen($id) > self::ID_MAX) {
            throw ValidationException::on('id', 'شناسه پیامک (id) نیامده است.');
        }
        $amount = $body['amount'] ?? null;
        // A whole number of Rial, as an integer, digits or a float with no fraction (a JSON `1500370.0`).
        $whole = (is_int($amount) && $amount >= 0) || (is_string($amount) && ctype_digit($amount))
            || (is_float($amount) && $amount >= 0 && $amount < 1e15 && floor($amount) === $amount);
        if ($amount !== null && !$whole) {
            throw ValidationException::on('amount', 'مبلغ (amount) باید عدد ریال باشد.');
        }

        return new IncomingDeposit(
            externalId: trim($id),
            // A sum in another unit counts as none read: the SMS is kept, and approves nothing.
            amountRial: $amount === null || ($body['unit'] ?? 'rial') !== 'rial' ? null : (int) $amount,
            isDeposit: is_bool($body['is_deposit'] ?? null) ? $body['is_deposit'] : null,
            test: ($body['test'] ?? false) === true,
            receivedAt: self::when($body),
            bank: self::short($body['bank'] ?? null),
            sender: self::short($body['sender'] ?? null),
            body: is_string($body['body'] ?? null) ? $body['body'] : '',
        );
    }

    /**
     * When the phone got it: `received_at_ms`, else `received_at`, else now (an app that sends neither).
     *
     * @param array<string, mixed> $body
     */
    private static function when(array $body): Carbon
    {
        $ms = $body['received_at_ms'] ?? null;
        if (is_int($ms) && $ms > 0) {
            return Carbon::createFromTimestampMs($ms)->utc();
        }
        $at = $body['received_at'] ?? null;
        if (is_string($at) && $at !== '') {
            try {
                return Carbon::parse($at)->utc();
            } catch (\Throwable) {
                // Not a time: now, as below.
            }
        }

        return now()->utc();
    }

    /** A bank's or a sender's name, trimmed and cut to LABEL_MAX; null for none. */
    private static function short(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, self::LABEL_MAX) : null;
    }
}
