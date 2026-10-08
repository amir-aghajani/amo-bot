<?php

declare(strict_types=1);

namespace App\Modules\Store\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Payments\DTO\Shortfall;
use App\Support\Money;

/**
 * A checkout on the shop's website that paid nothing (Payments\Services\Checkout), in the website's words: the wallet
 * short of the price (422 on `method_id`, what is missing), the order paid or closed elsewhere in the same moment
 * (409), a request made again whose order was cancelled since — by support, or unpaid in its time, maybe days ago (409,
 * words of its own) —, the wallet's own refusal as it was charged (422 on `method_id`, its reason), and the shop taking
 * no orders while its bot is switched off (503 — a receipt too).
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

    /** `$reason`: the payment's — its gateway's words. */
    public static function refused(?string $reason): self
    {
        return new self($reason ?? self::REFUSED, 422, 'method_id');
    }

    public static function paused(): self
    {
        return new self(self::PAUSED, 503, null);
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
