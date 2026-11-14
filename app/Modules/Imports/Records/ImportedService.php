<?php

declare(strict_types=1);

namespace App\Modules\Imports\Records;

/**
 * A customer's service in the other bot: a client on one of its panels, found by its name on the server that panel maps
 * to and taken into the shop as it stands there — its link, traffic, end and switch are the panel's, nothing on the
 * panel changes.
 */
final class ImportedService
{
    public function __construct(
        /** The other bot's id for it. */
        public readonly string $key,
        /** Its customer: ImportedCustomer::$key. */
        public readonly string $customerKey,
        /** Its panel: ImportedPanel::$key. */
        public readonly string $panelKey,
        /** The client's name on that panel, exactly as the panel has it. */
        public readonly string $remoteName,
        /** The plan it was bought from (ImportedPlan::$key); null for none or one the import cannot tell. */
        public readonly ?string $planKey = null,
        /** The customer's own name for it («نام دلخواه»); the core cuts it to fit. Null for none. */
        public readonly ?string $label = null,
        /** A free test account. */
        public readonly bool $test = false,
        /** The other bot's reseller bot it was sold by (its name); null for the bot itself. */
        public readonly ?string $reseller = null,
    ) {}
}
