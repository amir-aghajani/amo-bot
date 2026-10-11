<?php

declare(strict_types=1);

namespace App\Core\Captcha;

use App\Core\Drivers\Registry;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Http\RequestOrigin;
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Every check of a website's captcha goes through here: what a page draws (widget()), a challenge (challenge()), a
 * token judged for the site's backend (verify()), and the `captcha` field of the shop's own forms (check()). A site that
 * asks no captcha is asked nothing.
 */
final class Verifier
{
    /** A form's token that is none, or did not pass: under `captcha`. */
    public const REFUSED = 'تایید امنیتی انجام نشد؛ دوباره تلاش کنید.';

    /** The longest token judged (Turnstile's are 2048 at most); longer is refused without asking. */
    public const TOKEN_MAX = 4096;

    /** @param Registry<CaptchaDriver> $drivers */
    public function __construct(
        private readonly Registry $drivers,
        private readonly RequestOrigin $origin,
    ) {}

    /** @return list<CaptchaDriver> Every captcha a site may ask, in the order a screen offers them */
    public function drivers(): array
    {
        return $this->drivers->all();
    }

    /** The captcha `$key` names; null for a key no driver has. */
    public function driver(string $key): ?CaptchaDriver
    {
        return $this->drivers->find($key);
    }

    /** Whether the site asks a captcha. */
    public function asks(CaptchaSite $site): bool
    {
        return $this->driverOf($site) !== null;
    }

    /**
     * What a page draws the widget with: the driver, its public key, and whether it fetches its challenge from the
     * shop; null while the site asks no captcha.
     *
     * @return array{driver: string, site_key: string|null, challenges: bool}|null
     */
    public function widget(CaptchaSite $site): ?array
    {
        $driver = $this->driverOf($site);

        return $driver === null ? null : [
            'driver' => $driver->key(),
            'site_key' => $driver->siteKey($this->values($driver, $site)),
            'challenges' => $driver instanceof IssuesChallenges,
        ];
    }

    /**
     * A challenge for the site's widget, bound to `$action` when named; null when its captcha issues none.
     *
     * @return array<string, mixed>|null
     */
    public function challenge(CaptchaSite $site, ?string $action): ?array
    {
        $driver = $this->driverOf($site);

        return $driver instanceof IssuesChallenges ? $driver->challenge($this->values($driver, $site), $action) : null;
    }

    /**
     * Judge a token for the site (and `$action`, when named); null while the site asks no captcha.
     *
     * @throws CaptchaUnavailableException 503: it could not be judged
     */
    public function verify(CaptchaSite $site, string $token, ?string $action, ?string $visitor): ?CaptchaVerdict
    {
        $driver = $this->driverOf($site);
        if ($driver === null) {
            return null;
        }
        if ($token === '' || strlen($token) > self::TOKEN_MAX) {
            return CaptchaVerdict::refused([$token === '' ? 'missing-input-response' : 'invalid-input-response']);
        }

        return $driver->verify($this->values($driver, $site), new CaptchaAttempt($token, $action, $site->captchaHosts(), $visitor));
    }

    /**
     * Judge a shop form's `captcha` field for `$action`, from the request's visitor; nothing while the site asks none.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `captcha`: none, or not passed
     * @throws CaptchaUnavailableException 503: it could not be judged
     */
    public function check(CaptchaSite $site, ServerRequestInterface $request, array $input, string $action): void
    {
        $verdict = $this->verify($site, Input::text($input, 'captcha'), $action, $this->origin->clientIp($request));
        if ($verdict !== null && !$verdict->passed) {
            throw ValidationException::on('captcha', self::REFUSED);
        }
    }

    private function driverOf(CaptchaSite $site): ?CaptchaDriver
    {
        $key = $site->captchaDriver();

        return $key === null ? null : $this->drivers->get($key);
    }

    /** @return array<string, mixed> The driver's form values, read from what the site keeps */
    private function values(CaptchaDriver $driver, CaptchaSite $site): array
    {
        $config = $site->captchaConfig();

        return $driver->describe()->form->values(static fn(Field $field): mixed => $config[$field->key] ?? null);
    }
}
