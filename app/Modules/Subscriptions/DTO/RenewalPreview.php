<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\DTO;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;

/**
 * A renewal before it is paid (Subscriptions\Services\CustomerRenewal::preview()): the plan the service is renewed on —
 * its own —, what it costs today, and the service as the renewal would leave it — a copy of its row with the renewal's
 * terms applied (ServiceTerms::renewal()->preview()), nothing saved, no panel asked —, which the bot's checkout and the
 * website's both show.
 */
final class RenewalPreview
{
    public function __construct(
        public readonly Plan $plan,
        public readonly string $price,
        public readonly Subscription $after,
    ) {}
}
