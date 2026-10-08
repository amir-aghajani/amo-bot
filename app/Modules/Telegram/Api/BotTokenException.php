<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Api;

use App\Core\Exceptions\DomainRuleException;

/** A bot token the shop cannot keep — not a token, refused by Telegram, or not checkable now — said on the `token` field. */
final class BotTokenException extends DomainRuleException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    protected function field(): string
    {
        return 'token';
    }
}
