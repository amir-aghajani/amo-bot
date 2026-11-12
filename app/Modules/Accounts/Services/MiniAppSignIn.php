<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Accounts\Contracts\SignInSite;
use App\Modules\Accounts\DTO\MiniAppLaunch;
use App\Modules\Accounts\DTO\SignedIn;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Support\LaunchData;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Bots\Services\Bots;
use App\Modules\Users\Services\Customers;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Signing in on the shop's Telegram mini app: the launch data Telegram hands the app (`init_data`), signed with the shop's
 * bot's token (Support\LaunchData), proves the Telegram account it is about — the bot's own customer, with their services
 * and wallet, or a newcomer registered the way the bot does. The data is made when the app opens, so it signs in for a
 * day (MAX_AGE_SECONDS), and the session counts as proven when it was made, not when it reached the shop: an old launch
 * signs in, but changes nothing a recent sign-in guards until the customer proves their account again.
 *
 * Every proof runs under the address's sign-in throttle, as the website's ways in do; a failed one counts.
 */
final class MiniAppSignIn
{
    /** How long after the app opened its launch data still signs in. */
    public const MAX_AGE_SECONDS = 86_400;

    public function __construct(
        private readonly Bots $bots,
        private readonly Customers $customers,
        private readonly ProviderSignIn $provider,
        private readonly SignInThrottle $throttle,
    ) {}

    /**
     * Sign the customer in by `init_data` — a newcomer registered with `referral_code`, the code a referral link opened
     * the app with.
     *
     * @param array<string, mixed> $input {init_data, referral_code?}
     * @throws SignInRefusedException 401 the data does not hold; 403 banned
     * @throws ValidationException 422 on `init_data`: none sent
     * @throws TooManyAttemptsException 429 while this address must wait
     */
    public function signIn(SignInSite $site, ServerRequestInterface $request, array $input): SignedIn
    {
        $launch = $this->launch($site, $request, $input, self::MAX_AGE_SECONDS, signedIn: false);
        $user = $this->customers->byTelegram($launch->telegramId, $launch->profile, ProviderSignIn::referralCode($input));

        return $this->provider->open($user, $request, SignInMethod::MiniApp, $launch->madeAt);
    }

    /**
     * The launch `init_data` carries, made at most `$maxAgeSeconds` ago — what a sign-in signs in by, and what a signed-in
     * customer proves their account with again (`$signedIn`: a refusal is then a 422 on `init_data`, since a 401 would tell
     * the app their session ended).
     *
     * @param array<string, mixed> $input
     * @throws SignInRefusedException 401 the data does not hold (a sign-in's)
     * @throws ValidationException 422 on `init_data`: none sent, or (a signed-in customer's) it does not hold
     * @throws TooManyAttemptsException 429 while this address must wait
     */
    public function launch(SignInSite $site, ServerRequestInterface $request, array $input, int $maxAgeSeconds, bool $signedIn): MiniAppLaunch
    {
        $initData = is_string($input['init_data'] ?? null) ? trim($input['init_data']) : '';
        if ($initData === '') {
            throw ValidationException::on('init_data', 'init_data را بفرستید: همان Telegram.WebApp.initData که تلگرام به مینی‌اپ داده است.');
        }
        $this->throttle->check(SignInThrottle::WEBSITE, $request);

        $launch = LaunchData::read($initData, $this->bots->token($site->shop()), $maxAgeSeconds);
        if ($launch === null) {
            $this->throttle->failed(SignInThrottle::WEBSITE, $request);
            if ($signedIn) {
                throw ValidationException::on('init_data', SignInRefusedException::LAUNCH_REFUSED);
            }

            throw SignInRefusedException::launchRefused();
        }

        return $launch;
    }
}
