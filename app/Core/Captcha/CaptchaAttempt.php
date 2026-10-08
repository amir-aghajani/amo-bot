<?php

declare(strict_types=1);

namespace App\Core\Captcha;

/** A token to judge: what the widget handed the form, the action it is for (when one is), where the site's pages are, and who sent it (when known). */
final readonly class CaptchaAttempt
{
    /** @param list<string> $hosts The site's hosts (CaptchaSite::captchaHosts()) */
    public function __construct(
        public string $token,
        /** The action the form is for — `sign_up`, a site's own `review` —; null: any. */
        public ?string $action,
        public array $hosts,
        /** The visitor's address, when known: the token came with them (a sign-in's form), or a site's backend names them; null otherwise. */
        public ?string $visitor,
    ) {}
}
