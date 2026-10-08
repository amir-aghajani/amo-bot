<?php

declare(strict_types=1);

namespace App\Modules\Store\Enums;

/**
 * What a shop lets its admins do from its website beyond its daily work (the website's `staff_grants`, each a switch of
 * its «مدیران سایت» — off until the shop's owner turns it on): the catalogue's plans and categories (`catalog`), a
 * customer's wallet credited or debited by hand (`wallet`), a payment given back (`refunds`), days and traffic given to a
 * service (`extend`), a service deleted for good (`delete`), and a customer's website account taken in hand — their
 * two-factor sign-in turned off, every device of theirs signed out (`account_security`). An operation of the panels' API
 * that needs one says which in its route's ARGUMENT (routes/api.php); the panels' principals hold every one.
 */
enum StaffGrant: string
{
    /** The route argument an operation names its grant in. */
    public const ARGUMENT = 'staff_grant';

    case Catalog = 'catalog';
    case Wallet = 'wallet';
    case Refunds = 'refunds';
    case Extend = 'extend';
    case Delete = 'delete';
    case AccountSecurity = 'account_security';

    /**
     * The grants `$values` names, each once, in their own order — what is no grant left out.
     *
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    public static function among(array $values): array
    {
        $named = array_filter(self::cases(), static fn(self $grant): bool => in_array($grant->value, $values, true));

        return array_values(array_map(static fn(self $grant): string => $grant->value, $named));
    }
}
