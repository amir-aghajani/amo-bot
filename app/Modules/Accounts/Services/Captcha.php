<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Captcha\Verifier;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Accounts\Contracts\SignInSite;
use App\Modules\Accounts\Enums\CaptchaAction;
use App\Modules\Auth\Services\SignInThrottle;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The captcha a website asks of a sign-up, a sign-in with a password and a password reset (Core\Captcha\Verifier, the
 * website's driver): the form's `captcha`, the widget's token, judged for its action once the form's own fields passed —
 * a Turnstile token is good once — and counted first against what the address network that sent it may cost the shop
 * (SignInThrottle::issuing()): a refused token keeps its count, so a bot's guesses cost it what asking for codes costs,
 * and once that is spent its next token is refused unjudged. A site that asks none is asked nothing.
 */
final class Captcha
{
    public function __construct(
        private readonly Verifier $verifier,
        private readonly SignInThrottle $throttle,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `captcha`: none, or not passed
     * @throws TooManyAttemptsException 429 while this address network asked too much
     * @throws CaptchaUnavailableException 503: it could not be judged
     */
    public function check(SignInSite $site, ServerRequestInterface $request, array $input, CaptchaAction $action): void
    {
        if (!$this->verifier->asks($site)) {
            return;
        }
        $this->throttle->issuing($request);

        try {
            $this->verifier->check($site, $request, $input, $action->value);
        } catch (CaptchaUnavailableException $e) {
            $this->throttle->issuedNothing($request);

            throw $e;
        }
        $this->throttle->issuedNothing($request);
    }
}
