<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTO\WebhookWord;

/**
 * An online gateway whose word on a payment comes only as a webhook it signs — it answers no question about one
 * (Variza) —, to the shop's one address for its driver (Controllers\GatewayWebhookController). The signature, made with a
 * secret only the gateway and the merchant hold, is what makes the word the gateway's: one that does not check out is
 * not this method's. The shop records the word on the payment it names (Services\OnlinePayments::hear()), and the
 * gateway's check() reads it from there.
 */
interface SignedWebhook extends OnlineGateway
{
    /**
     * What a webhook to the shop says, when its signature is this method's: the payment it names, by the reference the
     * gateway gave it at open() — none for an event about no payment —, and its word. Null when the signature is not this
     * method's (another method's, or none).
     *
     * @param array<string, string> $headers By lower-case name
     */
    public function hear(string $body, array $headers): ?WebhookWord;
}
