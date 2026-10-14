<?php

declare(strict_types=1);

namespace App\Modules\Store\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Bots\Exceptions\SellingSuspendedException;
use App\Modules\Payments\DTO\Shortfall;
use App\Support\Money;

/**
 * A website checkout that paid nothing: the wallet short (422 on `method_id`), the order paid or closed elsewhere a moment
 * ago (409), a repeated request whose order was cancelled since (409), the wallet's refusal as it was charged (422), or
 * the shop taking no orders while its bot is off, or while an agent's selling is suspended (503).
 */
final class CheckoutRefusedException extends DomainRuleException
{
    public const SHORT = 'موجودی کیف پول کافی نیست؛ %s کم است.';
    public const CLOSED = 'این سفارش همین حالا جای دیگری پرداخت یا بسته شد؛ وضعیت آن را در سفارش‌ها ببینید.';
    public const CANCELLED = 'این سفارش لغو شده است؛ پرداخت نشد یا پشتیبانی آن را لغو کرد. برای خرید دوباره از نو شروع کنید.';
    public const REFUSED = 'پرداخت انجام نشد.';
    public const PAUSED = 'فروشگاه فعلا سفارش نمی‌گیرد؛ کمی بعد دوباره سر بزنید.';

    private function __construct(
        string $message,
        private readonly int $answer,
        private readonly ?string $about,
    ) {
        parent::__construct($message);
    }

    public static function short(Shortfall $shortfall): self
    {
        return new self(sprintf(self::SHORT, Money::format($shortfall->missing)), 422, 'method_id');
    }

    public static function closed(): self
    {
        return new self(self::CLOSED, 409, null);
    }

    public static function cancelled(): self
    {
        return new self(self::CANCELLED, 409, null);
    }

    /** `$reason`: the payment's note, the gateway's words. */
    public static function refused(?string $reason): self
    {
        return new self($reason ?? self::REFUSED, 422, 'method_id');
    }

    public static function paused(): self
    {
        return new self(self::PAUSED, 503, null);
    }

    /** An agent's shop whose selling the main shop suspended (Bot::sells()): no new order, a 503 as paused(). */
    public static function suspended(): self
    {
        return new self(SellingSuspendedException::MESSAGE, 503, null);
    }

    public function status(): int
    {
        return $this->answer;
    }

    protected function field(): ?string
    {
        return $this->about;
    }
}
