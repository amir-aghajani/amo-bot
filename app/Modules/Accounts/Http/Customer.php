<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http;

use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Users\Models\User;
use Psr\Http\Message\ServerRequestInterface;

/** The signed-in customer of a website request and the session it came with, put on the request by CustomerAuthMiddleware. */
final class Customer
{
    /** The request attribute it is put under. */
    public const ATTRIBUTE = 'customer';

    public function __construct(
        public readonly CustomerSession $session,
        public readonly User $user,
    ) {}

    /** The customer of a request that passed CustomerAuthMiddleware. */
    public static function of(ServerRequestInterface $request): self
    {
        return self::optional($request) ?? throw new \LogicException('The request did not pass the customer\'s auth middleware.');
    }

    /** The customer of a request that may come from a guest (OptionalCustomerMiddleware); null for a guest. */
    public static function optional(ServerRequestInterface $request): ?self
    {
        $customer = $request->getAttribute(self::ATTRIBUTE);

        return $customer instanceof self ? $customer : null;
    }
}
