<?php

declare(strict_types=1);

namespace App\Modules\Store\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * The website's captcha asked for what it does not have: a token judged while the website asks none (409 — its owner
 * sets one up in the panel), or a challenge from a captcha whose widget asks the shop for none (404 — Turnstile's draws
 * its own).
 */
final class NoCaptchaException extends DomainRuleException
{
    public const OFF = 'این وب‌سایت تایید امنیتی ندارد؛ آن را در پنل، تنظیمات وب‌سایت، روشن کنید.';
    public const NO_CHALLENGES = 'تایید امنیتی این وب‌سایت چالشی از فروشگاه نمی‌گیرد.';

    private function __construct(string $message, private readonly int $answer)
    {
        parent::__construct($message);
    }

    public static function off(): self
    {
        return new self(self::OFF, 409);
    }

    public static function noChallenges(): self
    {
        return new self(self::NO_CHALLENGES, 404);
    }

    public function status(): int
    {
        return $this->answer;
    }
}
