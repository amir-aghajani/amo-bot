<?php

declare(strict_types=1);

namespace App\Modules\Accounts\DTO;

/**
 * A way in being added to a customer's account is another account's of the same shop (Services\Identities): that
 * account as the customer may judge it is theirs, which of the two stays (the older), and the ticket that makes them
 * one (POST /me/merge, Services\MergeOffers::accept()).
 */
final class MergeOffer
{
    /**
     * @param array{name: string|null, created_at: string, services: int, orders: int, balance: string, telegram: bool, email: bool, google: bool} $account The other account
     * @param 'this'|'other' $keeps The account that stays: the one asking, or the other
     */
    public function __construct(
        public readonly string $token,
        public readonly int $expiresIn,
        public readonly array $account,
        public readonly string $keeps,
    ) {}

    /** @return array{merge: array{token: string, expires_in: int, account: array{name: string|null, created_at: string, services: int, orders: int, balance: string, telegram: bool, email: bool, google: bool}, keeps: 'this'|'other'}} */
    public function present(): array
    {
        return ['merge' => ['token' => $this->token, 'expires_in' => $this->expiresIn, 'account' => $this->account, 'keeps' => $this->keeps]];
    }
}
