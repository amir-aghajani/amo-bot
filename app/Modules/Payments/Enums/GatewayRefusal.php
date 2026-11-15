<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Why a gateway refused a call, as far as the owner can do something about it — what a GatewayUnavailableException may
 * say beside its log line, for words of the owner's own (Services\GatewayWebhooks). None for a gateway out of reach.
 */
enum GatewayRefusal: string
{
    /** The merchant's key is wrong, revoked, or none (it no longer decrypts). */
    case Key = 'key';
    /** The shop server's outgoing IP is not among the allowed IPs the merchant set at the gateway. */
    case Ip = 'ip';
    /** The merchant's account at the gateway is not active, or blocked. */
    case Blocked = 'blocked';
    /** Too many calls just now — or, a change, more than the gateway takes in a while. */
    case Limited = 'limited';
    /** The gateway would not take what was sent: a change's value not valid. */
    case Invalid = 'invalid';
    /** The gateway is in trouble (a 5xx), or answered nothing the shop reads. */
    case Trouble = 'trouble';
}
