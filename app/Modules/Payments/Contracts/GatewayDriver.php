<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Core\Drivers\Driver;

/**
 * A way the shop takes money: a stateless driver registered in `payment.drivers`. Its descriptor gives the panels its
 * words, the form of a method row's settings (kept in `payment_methods.config`) and two traits — `kind` (a GatewayKind)
 * and `builtin` (one row in every shop, never added or deleted: the wallet, and the outside row support records money
 * taken outside the shop with).
 */
interface GatewayDriver extends Driver
{
    /** The trait (Descriptor::$traits) that says how it settles: a GatewayKind's value. */
    public const KIND = 'kind';

    /** The trait that says it is part of the shop itself (the wallet, the outside row). */
    public const BUILTIN = 'builtin';

    /** The summary() of a method set to its gateway's test service, where no money moves. */
    public const TEST_MODE = 'حالت آزمایشی: پولی جابه‌جا نمی‌شود';

    /**
     * One line saying what the customer is told to pay to — a masked card and its holder —, on the method's row and on
     * each payment made with it; null when it has nothing of the kind (the wallet).
     *
     * @param array<string, mixed> $settings Its form's values by field name, as the row keeps them (Form::values())
     */
    public function summary(array $settings): ?string;

    /**
     * The gateway of one method row: what the customer does next once they picked it, and its part of a payment's
     * settlement.
     *
     * @param array<string, mixed> $settings Its form's values by field name, as the row keeps them (Form::values())
     */
    public function gateway(array $settings): GatewayInterface;
}
