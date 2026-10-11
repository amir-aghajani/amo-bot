<?php

declare(strict_types=1);

namespace App\Core\Captcha;

/** A token to judge, with the form's action (if any), the site's hosts and the visitor's address (if known). */
final readonly class CaptchaAttempt
{
    /** @param list<string> $hosts The site's hosts (CaptchaSite::captchaHosts()) */
    public function __construct(
        public string $token,
        /** The form's action (`sign_up`, `review`); null: any. */
        public ?string $action,
        public array $hosts,
        /** The visitor's address, handed to the provider; null when unknown. */
        public ?string $visitor,
    ) {}
}
