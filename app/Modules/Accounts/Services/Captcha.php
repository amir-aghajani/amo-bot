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
 * The captcha a website asks of a sign-up, a password sign-in and a password reset: the form's `captcha` token judged for
 * the form's action (Core\Captcha\Verifier), after the form's own fields passed. Each token is first counted against what
 * the address network may cost the shop (SignInThrottle::issuing()); a refused one keeps its count, so a bot's guesses
 * cost it what asking for codes costs, and once that budget is spent its tokens are refused unjudged.
 */
final class Captcha
{
    public function __construct(
        private readonly Verifier $verifier,
        private readonly SignInThrottle $throttle,
    ) {}

    /**
     * Judge the form's captcha, when the site asks one.
     *
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
        // A token that passed costs nothing; only refused ones keep their count.
        $this->throttle->issuedNothing($request);
    }
}
