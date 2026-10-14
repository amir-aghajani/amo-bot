<?php

declare(strict_types=1);

namespace App\Modules\Store\Http;

use App\Core\Exceptions\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The `Idempotency-Key` header every request that moves money carries — a checkout, a wallet transfer, an order support
 * makes by hand: a key the caller makes per attempt (a UUID), so the same request again is answered by what the key did,
 * never done twice (`request_keys` for orders, Payments\Services\Checkout; `wallet_transfers.request_key`). 1 to 64
 * Latin letters, digits, `-` and `_`.
 */
final class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public const MISSING = 'این درخواست باید هدر Idempotency-Key داشته باشد؛ برای هر بار انجام آن یک کلید تازه بسازید.';
    public const MALFORMED = 'Idempotency-Key باید 1 تا 64 کاراکتر از حروف و ارقام لاتین، «-» یا «_» باشد.';

    private const PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    /**
     * The request's key.
     *
     * @throws ValidationException 422 on `idempotency_key`: none sent, or not one
     */
    public static function of(ServerRequestInterface $request): string
    {
        if (!$request->hasHeader(self::HEADER)) {
            throw ValidationException::on('idempotency_key', self::MISSING);
        }
        $key = $request->getHeaderLine(self::HEADER);

        return preg_match(self::PATTERN, $key) === 1 ? $key : throw ValidationException::on('idempotency_key', self::MALFORMED);
    }
}
