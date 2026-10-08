<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

use App\Modules\Providers\Enums\AuthenticationFailure;

/**
 * The panel answered but would not let the shop in: `failure` says how, `panelMessage` keeps the panel's own words
 * when it gave some, and `hint` is the driver's remedy in Persian (where on that panel the token is made, which rights
 * it needs), appended to the generic explanation the admin reads.
 */
final class AuthenticationException extends ProviderException
{
    public function __construct(
        string $message,
        public readonly AuthenticationFailure $failure = AuthenticationFailure::Login,
        public readonly string $panelMessage = '',
        public readonly string $hint = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function missingCredentials(): self
    {
        return new self('No API token nor username and password are set for the panel.', AuthenticationFailure::Missing);
    }

    public static function rejectedToken(int $httpStatus, string $hint = ''): self
    {
        return new self("The panel refused the API token (HTTP {$httpStatus}).", AuthenticationFailure::Token, hint: $hint);
    }

    public static function insufficientScope(string $panelMessage, string $hint = ''): self
    {
        $detail = $panelMessage !== '' ? $panelMessage : 'HTTP 403';

        return new self("The API token may not call this endpoint: {$detail}", AuthenticationFailure::Scope, $panelMessage, $hint);
    }

    public static function loginFailed(string $panelMessage, bool $twoFactor, string $hint = ''): self
    {
        $reason = $panelMessage !== '' ? $panelMessage : 'no reason given';

        return $twoFactor
            ? new self("The panel refused the login: {$reason} (its two-factor authentication is on)", AuthenticationFailure::TwoFactor, $panelMessage, $hint)
            : new self("The panel refused the login: {$reason}", AuthenticationFailure::Login, $panelMessage, $hint);
    }

    public static function sessionRejected(int $httpStatus): self
    {
        return new self("The panel keeps refusing the session right after the login (HTTP {$httpStatus}).", AuthenticationFailure::Session);
    }

    public static function csrfRejected(): self
    {
        return new self('The panel refused the login request without a valid session CSRF token (HTTP 403).', AuthenticationFailure::Csrf);
    }

    public function unavailable(): bool
    {
        return true;
    }
}
