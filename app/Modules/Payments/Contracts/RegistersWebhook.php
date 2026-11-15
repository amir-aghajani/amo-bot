<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Enums\GatewayRefusal;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;

/**
 * An online gateway whose webhook address the shop sets itself, through the gateway's own API (Rialit): one address for
 * the merchant's whole account there, which another site of the merchant's may be using — so the shop reads it before it
 * changes it, and replaces another one only when the owner says so (Services\GatewayWebhooks). Both calls talk to the
 * gateway; a refusal says why (GatewayUnavailableException::$refusal) whenever the gateway's answer does. A driver's
 * gateway is of one type whatever its settings (GatewayRegistry::registersWebhook() asks one made of none).
 */
interface RegistersWebhook extends OnlineGateway
{
    /**
     * Where the gateway sends its webhooks now; null for nowhere (its webhooks are off).
     *
     * @throws GatewayUnavailableException out of reach, refused, or an answer that does not say
     */
    public function webhook(): ?string;

    /**
     * Sends the gateway's webhooks to `$url` from now on: where it says it sends them after the change.
     *
     * @throws GatewayUnavailableException as webhook(); an address it does not take is GatewayRefusal::Invalid, one
     *                                     change too many in a while GatewayRefusal::Limited
     */
    public function hook(string $url): ?string;
}
