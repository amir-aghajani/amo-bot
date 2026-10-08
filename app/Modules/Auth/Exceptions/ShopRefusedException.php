<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Auth\NamedShop;

/**
 * A panel request that cannot be worked in the shop it names (NamedShop): the owner's naming a bot that is not there —
 * a 404, never the main shop in its place —, an agent's naming another bot than theirs — a 403: an agent's panel works
 * in their bot's shop alone, whatever the request says.
 */
final class ShopRefusedException extends DomainRuleException
{
    public const NOT_FOUND = 'این فروشگاه پیدا نشد؛ شاید آدرسی که باز کردید درست نیست.';
    public const NOT_YOURS = 'این پنل فقط فروشگاه ربات خودتان را باز می‌کند.';

    private function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 404);
    }

    public static function notYours(): self
    {
        return new self(self::NOT_YOURS, 403);
    }

    public function status(): int
    {
        return $this->status;
    }

    protected function field(): string
    {
        return NamedShop::FIELD;
    }
}
