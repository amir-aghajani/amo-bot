<?php

declare(strict_types=1);

namespace App\Core\Captcha;

/**
 * A site whose forms a captcha guards, as the Verifier asks it: the captcha it asks — a driver's key, and what that
 * driver's form keeps for it — and the hosts its pages are on, which a token solved anywhere else does not pass. The
 * shop's website implements it (the Store module's Website, through the Accounts module's SignInSite).
 */
interface CaptchaSite
{
    /** The key of the captcha's driver the site asks; null while it asks none. */
    public function captchaDriver(): ?string;

    /** @return array<string, mixed> What the driver's form keeps for the site, by each field's key. */
    public function captchaConfig(): array;

    /** @return list<string> The hosts its pages are on (`shop.example`, `localhost`), in lower case. */
    public function captchaHosts(): array;
}
