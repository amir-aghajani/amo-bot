<?php

declare(strict_types=1);

namespace App\Core\Captcha;

/**
 * A site whose forms a captcha guards: the driver it asks, that driver's kept values, and the hosts its pages are on (a
 * token solved elsewhere does not pass). Implemented by the Store module's Website.
 */
interface CaptchaSite
{
    /** The key of the captcha's driver the site asks; null while it asks none. */
    public function captchaDriver(): ?string;

    /** @return array<string, mixed> The driver's form values the site keeps, by field key. */
    public function captchaConfig(): array;

    /** @return list<string> The hosts its pages are on (`shop.example`, `localhost`), in lower case. */
    public function captchaHosts(): array;
}
