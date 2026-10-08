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
 * The captcha a site asks, in one place for every form it guards: the widget a page draws (widget()), a challenge for a
 * widget that asks the shop for one (challenge()), a token judged for an action (verify() — a site's own backend asking
 * of a form of its own) or a form's token held to it (check() — the shop's own forms: a sign-up, a sign-in with a
 * password, a reset). Each in the driver the site names, with what its form keeps for the site (CaptchaSite), against
 * the site's hosts. A site that asks none is asked nothing.
 */
final class Verifier
{
    /** A form's token that is none, or did not pass: under `captcha`. */
    public const REFUSED = 'تایید امنیتی انجام نشد؛ دوباره تلاش کنید.';

    /** The longest token judged: a widget's is a few hundred characters, Turnstile's 2048 at most. */
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
     * What a page of the site draws the widget with — the driver, its public key (null for one without), and whether
     * the widget asks the shop for its challenge —; null while the site asks no captcha.
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
     * A challenge for the site's widget, bound to `$action` when one is named; null while it asks no captcha, or one
     * whose widget asks the shop for none.
     *
     * @return array<string, mixed>|null
     */
    public function challenge(CaptchaSite $site, ?string $action): ?array
    {
        $driver = $this->driverOf($site);

        return $driver instanceof IssuesChallenges ? $driver->challenge($this->values($driver, $site), $action) : null;
    }

    /**
     * A token judged for the site — for `$action` when one is named —, `$visitor` the address it came from when that is
     * known (the token came with them, or the site's backend names them); null while the site asks no captcha. A token
     * past TOKEN_MAX is refused unasked.
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
     * A form of the shop's own that the site's captcha guards: its `captcha` — the widget's token — judged for `$action`
     * from the visitor the request came from; nothing while the site asks none.
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

    /** @return array<string, mixed> The driver's form's values, as the site keeps them */
    private function values(CaptchaDriver $driver, CaptchaSite $site): array
    {
        $config = $site->captchaConfig();

        return $driver->describe()->form->values(static fn(Field $field): mixed => $config[$field->key] ?? null);
    }
}
