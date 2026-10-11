<?php

declare(strict_types=1);

namespace App\Modules\Store\Http;

use App\Core\Exceptions\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The `Idempotency-Key` header every ordering request carries: a key the website makes per checkout attempt (a UUID). The
 * same key from the same customer with the same way to pay is answered by the order it came to, never a second one
 * (`request_keys`, Payments\Services\Checkout). 1 to 64 Latin letters, digits, `-` and `_`.
 */
final class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public const MISSING = 'این درخواست باید هدر Idempotency-Key داشته باشد؛ برای هر بار پرداخت یک کلید تازه بسازید.';
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
