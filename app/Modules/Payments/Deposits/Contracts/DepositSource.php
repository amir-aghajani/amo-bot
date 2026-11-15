<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits\Contracts;

use App\Core\Exceptions\ValidationException;
use App\Modules\Payments\Deposits\DTO\IncomingDeposit;

/**
 * Where the shop's bank deposits come from, saying how its request reads: a phone app that forwards the bank's deposit
 * SMS to the shop's webhook (Payments\Controllers\DepositWebhookController, one per key in the address) — ForApp —, or
 * Rikhtam, which posts to an address of its own (Payments\Controllers\RikhtamWebhookController).
 */
interface DepositSource
{
    /** Its key, on each deposit (and in the shared webhook's address): "forapp", "rikhtam". */
    public function key(): string;

    /** Its name, as the panels show it. */
    public function label(): string;

    /**
     * The deposit its request carries.
     *
     * @param array<string, mixed> $body The request's body: ForApp's JSON, Rikhtam's form
     * @throws ValidationException 422: not what the source sends
     */
    public function read(array $body): IncomingDeposit;
}
