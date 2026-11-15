<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * An online gateway whose webhook, to the shop's one address for its driver (Controllers\GatewayWebhookController), only
 * names a payment (Rialit): it is not signed, so what it says of the payment is never believed — it is a reason to ask
 * the gateway itself (check()), like a callback, and nothing more. Its body says only whether it is the gateway's shape
 * at all and which payment it is about.
 */
interface NamingWebhook extends OnlineGateway
{
    /**
     * The gateway's reference of the payment a webhook to the shop is about — the one it gave at open() —; null for an
     * event about none (a test, a payment the gateway's own words do not name).
     *
     * @param array<string, string> $headers By lower-case name
     * @throws \InvalidArgumentException the body is not one of the gateway's webhooks at all
     */
    public function reference(string $body, array $headers): ?string;
}
