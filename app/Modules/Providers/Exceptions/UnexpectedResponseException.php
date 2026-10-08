<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

/**
 * Something answered, but not the panel API the driver speaks: a 404 for a route it does not know, a redirect (http to
 * https, a trailing slash), a proxy's HTML error page, a body that is not JSON. `httpStatus`, `location` and `excerpt`
 * are what was seen; `hint` is the driver's Persian guess at the cause (a missing base path, a panel too old).
 */
final class UnexpectedResponseException extends ProviderException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $excerpt = '',
        public readonly string $location = '',
        public readonly string $hint = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(rtrim("The panel did not answer as its API (HTTP {$httpStatus}): " . ($this->isRedirect() ? 'a redirect' : $excerpt), ': '), $httpStatus, $previous);
    }

    public function isRedirect(): bool
    {
        return $this->httpStatus >= 300 && $this->httpStatus < 400;
    }

    public function unavailable(): bool
    {
        return true;
    }
}
