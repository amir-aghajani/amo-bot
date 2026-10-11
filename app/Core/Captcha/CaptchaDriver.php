<?php

declare(strict_types=1);

namespace App\Core\Captcha;

use App\Core\Drivers\Driver;

/**
 * A captcha (Turnstile, ALTCHA): a widget a site's page shows, whose token the shop judges. A site keeps which one it
 * asks and its form's values (CaptchaSite); Verifier asks the driver.
 */
interface CaptchaDriver extends Driver
{
    /**
     * The public key a page draws the widget with; null for a driver without one.
     *
     * @param array<string, mixed> $values Its form's values by field name
     */
    public function siteKey(array $values): ?string;

    /**
     * Judge a token.
     *
     * @param array<string, mixed> $values
     * @throws CaptchaUnavailableException when it could not be judged (the provider out of reach or answering oddly)
     */
    public function verify(array $values, CaptchaAttempt $attempt): CaptchaVerdict;
}
