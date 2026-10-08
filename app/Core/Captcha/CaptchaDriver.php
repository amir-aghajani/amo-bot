<?php

declare(strict_types=1);

namespace App\Core\Captcha;

use App\Core\Drivers\Driver;

/**
 * A captcha — a widget a site's page shows, and the token it hands the form judged by the shop —: Cloudflare Turnstile,
 * ALTCHA (Drivers\*). A site keeps which one it asks and its form's values (CaptchaSite); the Verifier asks the driver.
 * A driver whose widget fetches its challenge from the shop issues them too (IssuesChallenges).
 */
interface CaptchaDriver extends Driver
{
    /**
     * The public key a page draws the widget with — never a secret —; null for a driver without one.
     *
     * @param array<string, mixed> $values Its form's values by field name, as the site keeps them (Form::values())
     */
    public function siteKey(array $values): ?string;

    /**
     * A token judged, as the driver passes it: whether it passed, and why not.
     *
     * @param array<string, mixed> $values
     * @throws CaptchaUnavailableException when it could not be judged — its provider out of reach, or not answering as its API does
     */
    public function verify(array $values, CaptchaAttempt $attempt): CaptchaVerdict;
}
