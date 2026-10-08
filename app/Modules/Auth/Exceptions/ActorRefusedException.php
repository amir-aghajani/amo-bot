<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A decision this actor may not make, whatever the row's state allows (403) — one of the shop's admins, who is its
 * customer too, deciding about themselves: their own payment approved or given back, their own wallet, their own service
 * changed (days and traffic, switched off or on, moved, deleted), their own request to become an agent approved (the
 * report group's buttons), their own review approved, rejected or deleted; or, on the shop's website, about an admin's
 * account or an agent's — the owner's partner — (a ban, two-factor sign-in, the devices signed in), which is the
 * panels'; or approving a payment there without a receipt in review — a service given away. The owner and an agent are
 * refused none of it.
 */
final class ActorRefusedException extends DomainRuleException
{
    public const OWN_PAYMENT = 'پرداخت خودتان را نمی‌توانید تایید یا بازپرداخت کنید؛ این کار با مدیر دیگری است.';
    public const OWN_WALLET = 'موجودی کیف پول خودتان را نمی‌توانید تغییر دهید؛ این کار با مدیر دیگری است.';
    public const OWN_SERVICE = 'سرویس خودتان را نمی‌توانید تغییر دهید؛ این کار با مدیر دیگری است.';
    public const OWN_REQUEST = 'درخواست نمایندگی خودتان را نمی‌توانید تایید کنید؛ این کار با مدیر دیگری است.';
    public const OWN_REVIEW = 'نظر خودتان را نمی‌توانید تایید، رد یا حذف کنید؛ این کار با مدیر دیگری است.';
    public const ADMIN_ACCOUNT = 'حساب مدیران فروشگاه فقط از پنل تغییر می‌کند.';
    public const AGENT_ACCOUNT = 'حساب نماینده‌ها فقط از پنل تغییر می‌کند.';
    public const RECEIPT_FIRST = 'از وب‌سایت فقط پرداختی تایید می‌شود که رسیدش رسیده و در انتظار بررسی است.';

    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function ownPayment(): self
    {
        return new self(self::OWN_PAYMENT);
    }

    public static function ownWallet(): self
    {
        return new self(self::OWN_WALLET);
    }

    public static function ownService(): self
    {
        return new self(self::OWN_SERVICE);
    }

    public static function ownRequest(): self
    {
        return new self(self::OWN_REQUEST);
    }

    public static function ownReview(): self
    {
        return new self(self::OWN_REVIEW);
    }

    public static function adminAccount(): self
    {
        return new self(self::ADMIN_ACCOUNT);
    }

    public static function agentAccount(): self
    {
        return new self(self::AGENT_ACCOUNT);
    }

    public static function receiptFirst(): self
    {
        return new self(self::RECEIPT_FIRST);
    }

    public function status(): int
    {
        return 403;
    }
}
