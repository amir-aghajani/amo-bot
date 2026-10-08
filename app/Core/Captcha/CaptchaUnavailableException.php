<?php

declare(strict_types=1);

namespace App\Core\Captcha;

use App\Core\Exceptions\DomainRuleException;

/** A captcha's token that could not be judged — its provider out of reach, or not answering as its API does: a 503 (the driver logged why). */
final class CaptchaUnavailableException extends DomainRuleException
{
    public const MESSAGE = 'تایید امنیتی در دسترس نیست؛ کمی بعد دوباره تلاش کنید.';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }

    public function status(): int
    {
        return 503;
    }
}
