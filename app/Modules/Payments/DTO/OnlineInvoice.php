<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/**
 * A payment opened on an online gateway (Contracts\OnlineGateway::open()): the page the customer pays on, until when
 * (null: the gateway did not say — the shop takes it for an hour), the gateway's id of it (null for none yet), and the
 * gateway's own details it reads back later — kept on the payment (`payments.invoice`).
 */
final class OnlineInvoice
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $url,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?string $reference,
        public readonly array $details,
    ) {}

    /** Until when a gateway's page takes money: the moment it gave (`$at`, as its answer writes one), else `$minutes` from now. */
    public static function until(mixed $at, int $minutes): \DateTimeImmutable
    {
        if (is_string($at) && $at !== '') {
            try {
                return (new \DateTimeImmutable($at))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                // Not a moment it can be read as: the minutes say it.
            }
        }

        return now()->utc()->addMinutes($minutes)->toDateTimeImmutable();
    }
}
