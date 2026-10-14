<?php

declare(strict_types=1);

namespace App\Modules\Store\Enums;

/**
 * What a shop lets its admins do from its website beyond the daily work (`staff_grants`, a switch each in «مدیران سایت»,
 * all off by default): the catalogue, a wallet adjusted by hand, refunds, receipts dismissed in bulk (`payments`),
 * extending a service, deleting one or handing it to another customer (`delete`: either takes it from its customer), an
 * order made for a customer by hand (`manual_orders`: a service delivered on the admin's word), and a customer's website
 * account security. An operation that needs one names it in its route's ARGUMENT (routes/api.php); the panels'
 * principals hold every grant.
 */
enum StaffGrant: string
{
    /** The route argument an operation names its grant in. */
    public const ARGUMENT = 'staff_grant';

    case Catalog = 'catalog';
    case Wallet = 'wallet';
    case Refunds = 'refunds';
    case Payments = 'payments';
    case Extend = 'extend';
    case ManualOrders = 'manual_orders';
    case Delete = 'delete';
    case AccountSecurity = 'account_security';

    /**
     * The grants `$values` names, each once, in the enum's order; anything that is no grant is left out.
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
