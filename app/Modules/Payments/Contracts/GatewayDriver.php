<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Core\Drivers\Driver;

/**
 * A way the shop takes money (Drivers\*): one stateless definition per driver, registered in bootstrap/container.php
 * (`payment.drivers`, behind GatewayRegistry). Its descriptor is what the panels show of it — its words, the form of a
 * method row's settings (each field kept in `payment_methods.config` under its key), and its traits: how it settles
 * (`kind`, a GatewayKind's value) and whether it is part of the shop itself (`builtin`: one row in every shop, made
 * with the shop, never added again nor deleted). It words a method's one line, and makes the gateway of each method row
 * from the row's settings — what its form reads of what the row keeps (GatewayRegistry).
 */
interface GatewayDriver extends Driver
{
    /** The trait (Descriptor::$traits) that says how it settles: a GatewayKind's value. */
    public const KIND = 'kind';

    /** The trait that says it is part of the shop itself (the wallet). */
    public const BUILTIN = 'builtin';

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
