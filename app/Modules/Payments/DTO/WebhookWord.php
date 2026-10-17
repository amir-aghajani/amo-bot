<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/**
 * A signed webhook's word (Contracts\SignedWebhook::hear()): the payment it names, by the gateway's reference for it —
 * null for an event about no payment —, and what the gateway said of it, kept on the payment as it is (its invoice's
 * `heard`) for the gateway's check() to read.
 */
final class WebhookWord
{
    /** @param array<string, mixed> $word */
    public function __construct(
        public readonly ?string $reference,
        public readonly array $word,
    ) {}
}
