<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A guest's review — no customer signed in — while the website asks no captcha: nothing would stand between a bot and
 * the shop's queue, so it takes reviews from its signed-in customers alone (Services\Reviews). A 403 in the customer's
 * words: sign in first.
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
