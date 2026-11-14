<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits\Contracts;

use App\Core\Exceptions\ValidationException;
use App\Modules\Payments\Deposits\DTO\IncomingDeposit;

/**
 * A phone app that forwards the bank's deposit SMS to the shop's webhook (Payments\Controllers\DepositWebhookController),
 * one per key in the address: it says how its body reads. ForApp is the first.
 */
interface DepositSource
{
    /** Its key, in the webhook's address and on each deposit: "forapp". */
    public function key(): string;

    /** Its name, as the panels show it. */
    public function label(): string;

    /**
     * The SMS its request carries.
     *
     * @param array<string, mixed> $body The request's JSON body
     * @throws ValidationException 422: not what the app sends
     */
    public function read(array $body): IncomingDeposit;
}
