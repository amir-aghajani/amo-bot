<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http;

use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Users\Models\User;
use Psr\Http\Message\ServerRequestInterface;

/** The customer a website's request was made for, and the session it came with — put on it by CustomerAuthMiddleware. */
final class Customer
{
    /** The request attribute the customer is put under. */
    public const ATTRIBUTE = 'customer';

    public function __construct(
        public readonly CustomerSession $session,
        public readonly User $user,
    ) {}

    /** The customer of a request that passed CustomerAuthMiddleware. */
    public static function of(ServerRequestInterface $request): self
    {
        $customer = $request->getAttribute(self::ATTRIBUTE);

        return $customer instanceof self ? $customer : throw new \LogicException('The request did not pass the customer\'s auth middleware.');
    }
}
