<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Captcha\Verifier;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\RequestOrigin;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Store\Exceptions\NoCaptchaException;
use App\Modules\Store\Models\Website;
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The website's captcha for any form of its own — a review, a contact form —, the shop holding its secret
 * (Core\Captcha\Verifier): the challenge a widget that asks the shop for one solves (challenge()), and a token judged
 * for the site's backend, which asks the shop server to server — a browser's word that it passed proves nothing —
 * (verify()). A token is taken once: Cloudflare takes a Turnstile token once, the shop an ALTCHA solution once in the
 * quarter of an hour its challenge lives. What the backend asks is the website's own budget, never the sign-ins'
 * (SignInThrottle::verifyingCaptcha()): every check counted against the website — its backend is one address for all
 * its visitors —, and every refusal against the visitor's network, which the backend names (`remoteip`, handed to the
 * captcha's provider too), else against the backend's own; each counted before it is judged, so once a network's
 * refusals are spent its next token is refused unjudged — its provider not asked —, and a bot behind the site's forms
 * spends its own network's, not the site's other visitors'.
 */
final class WebsiteCaptcha
{
    /** An action as a widget names one: Turnstile's `data-action` takes 32 of these characters. */
    private const ACTION = '/^[A-Za-z0-9_-]{1,32}$/';

    private const NO_TOKEN = 'توکن تایید امنیتی (token) را بفرستید؛ همان که ویجت به فرم داد.';
    private const BAD_ACTION = 'action باید 1 تا 32 حرف لاتین، عدد، - یا _ باشد.';
    private const BAD_VISITOR = 'remoteip باید آدرس IP بازدیدکننده باشد (IPv4 یا IPv6).';

    public function __construct(
        private readonly Verifier $verifier,
        private readonly SignInThrottle $throttle,
        private readonly RequestOrigin $origin,
    ) {}

    /**
     * A challenge for the website's widget — bound to `action` when the query names one —, while its captcha's widget
     * asks the shop for one.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     * @throws ValidationException 422 on `action`
     * @throws NoCaptchaException 404: none asked, or one whose widget asks the shop for none
     */
    public function challenge(Website $website, array $query): array
    {
        $errors = [];
        $action = self::action($query, $errors);
        ValidationException::ifAny($errors);

        return $this->verifier->challenge($website, $action) ?? throw NoCaptchaException::noChallenges();
    }

    /**
     * A token judged for the website's backend, for `action` when it names one, from the visitor `remoteip` names when
     * it does — passed or not, and the codes of why not; when it was judged.
     *
     * @param array<string, mixed> $input {token, action?, remoteip?}
     * @return array{passed: bool, action: string|null, hostname: string|null, verified_at: string, reasons: list<string>}
     * @throws ValidationException 422 on `token`, on `action`, on `remoteip`
     * @throws NoCaptchaException 409: the website asks no captcha
     * @throws TooManyAttemptsException 429
     * @throws CaptchaUnavailableException 503: it could not be judged
     */
    public function verify(Website $website, ServerRequestInterface $request, array $input): array
    {
        $errors = [];
        $token = Input::text($input, 'token');
        if ($token === '' || strlen($token) > Verifier::TOKEN_MAX) {
            $errors['token'][] = self::NO_TOKEN;
        }
        $action = self::action($input, $errors);
        $visitor = Input::text($input, 'remoteip');
        if ($visitor !== '' && filter_var($visitor, FILTER_VALIDATE_IP) === false) {
            $errors['remoteip'][] = self::BAD_VISITOR;
        }
        ValidationException::ifAny($errors);
        if (!$this->verifier->asks($website)) {
            throw NoCaptchaException::off();
        }

        $network = $visitor !== '' ? RequestOrigin::network($visitor) : $this->origin->clientNetwork($request);
        $this->throttle->verifyingCaptcha($website->key, $network);
        try {
            $verdict = $this->verifier->verify($website, $token, $action, $visitor !== '' ? $visitor : null) ?? throw NoCaptchaException::off();
        } catch (CaptchaUnavailableException $e) {
            $this->throttle->captchaNotRefused($website->key, $network);

            throw $e;
        }
        if ($verdict->passed) {
            $this->throttle->captchaNotRefused($website->key, $network);
        }

        return [
            'passed' => $verdict->passed,
            'action' => $verdict->action,
            'hostname' => $verdict->hostname,
            'verified_at' => now()->toIso8601String(),
            'reasons' => $verdict->reasons,
        ];
    }

    /**
     * The action an input names — none: null —, or why it is none under `action`.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function action(array $input, array &$errors): ?string
    {
        $action = $input['action'] ?? null;
        if ($action === null || $action === '') {
            return null;
        }
        if (!is_string($action) || preg_match(self::ACTION, $action) !== 1) {
            $errors['action'][] = self::BAD_ACTION;

            return null;
        }

        return $action;
    }
}
