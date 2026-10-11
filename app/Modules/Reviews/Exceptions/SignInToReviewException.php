<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A guest's review while the website asks no captcha: without one nothing would stop a bot, so only signed-in customers
 * may review. A 403 worded for the customer: sign in first.
 */
final class SignInToReviewException extends DomainRuleException
{
    public const MESSAGE = 'برای نوشتن نظر، اول وارد حساب خود شوید.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function status(): int
    {
        return 403;
    }
}
