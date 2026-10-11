<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Accounts\DTO\SignedIn;
use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Oidc\IdTokenVerifier;
use App\Modules\Accounts\Oidc\OidcProvider;
use App\Modules\Accounts\Oidc\ProviderUnreachableException;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The steps every sign-in through an OpenID provider (TelegramSignIn, GoogleSignIn) shares: an id_token handed to the
 * page is checked against the nonce the shop gave first (nonced()), a proof runs under the address's sign-in throttle
 * (prove()), and the account it found gets a session (open()).
 */
final class ProviderSignIn
{
    public function __construct(
        private readonly IdTokenVerifier $verifier,
        private readonly AuthChallenges $challenges,
        private readonly SignInThrottle $throttle,
        private readonly Customers $customers,
        private readonly CustomerSessions $sessions,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The claims of an id_token the provider's own sign-in handed the page (`id_token`), posted with the nonce the site
     * got from POST /auth/nonce. The token is verified before the nonce is spent, so a provider out of reach leaves the
     * nonce for a retry; the nonce is then spent once and must be the token's own.
     *
     * @param array<string, mixed> $input {id_token, nonce}
     * @return array<string, mixed>
     * @throws ValidationException 422 on `nonce`: none sent
     * @throws SignInRefusedException 401: not the provider's token for this site, expired, or its nonce unknown or spent
     * @throws ProviderUnreachableException when the provider's keys cannot be had
     */
    public function nonced(OidcProvider $provider, string $audience, array $input): array
    {
        $nonce = Input::text($input, 'nonce');
        if ($nonce === '') {
            throw ValidationException::on('nonce', 'nonce ورود را هم بفرستید؛ همان که POST /auth/nonce داد.');
        }

        $claims = $this->verifier->verify($provider, Input::text($input, 'id_token'), $audience);
        if ($this->challenges->spend(ChallengePurpose::Nonce, $nonce) === null || !self::carries($claims, $nonce)) {
            throw SignInRefusedException::signInSpent();
        }

        return $claims;
    }

    /**
     * Whether the token's claims carry `$nonce`, the one its sign-in began with.
     *
     * @param array<string, mixed> $claims
     */
    public static function carries(array $claims, string $nonce): bool
    {
        return is_string($claims['nonce'] ?? null) && hash_equals($nonce, $claims['nonce']);
    }

    /**
     * Run a proof of a provider account as a sign-in attempt: the address's throttle is asked first, and a proof that
     * does not hold counts as a failed sign-in. For a signed-in customer (`$signedIn`: linking a way in, or proving one
     * again) the refusal becomes a 422 on `$field`, since a 401 would tell the site their session ended. A provider out of
     * reach is logged and answered by `$unreachable` (a 502), counting for nothing.
     *
     * @template T
     * @param \Closure(): T $prove
     * @param \Closure(ProviderUnreachableException): \Throwable $unreachable
     * @return T
     * @throws SignInRefusedException 401 the proof does not hold (a sign-in's)
     * @throws ValidationException 422 on `$field` (a signed-in customer's)
     * @throws TooManyAttemptsException 429 while the address must wait
     */
    public function prove(OidcProvider $provider, ServerRequestInterface $request, bool $signedIn, string $field, \Closure $prove, \Closure $unreachable): mixed
    {
        $this->throttle->check(SignInThrottle::WEBSITE, $request);

        try {
            return $prove();
        } catch (SignInRefusedException $e) {
            if ($e->failedSignIn()) {
                $this->throttle->failed(SignInThrottle::WEBSITE, $request);
                if ($signedIn) {
                    throw ValidationException::on($field, $e->getMessage());
                }
            }

            throw $e;
        } catch (ProviderUnreachableException $e) {
            // The owner's to look into when it lasts: a host that cannot reach the provider signs nobody in.
            $this->logger->warning("A sign-in on the website could not ask {$provider->label}: {message}", ['message' => $e->getMessage()]);

            throw $unreachable($e);
        }
    }

    /**
     * A session for the customer a provider proved, on the device the request came from — refused for a banned one; a
     * returning customer's visit is recorded.
     *
     * @throws SignInRefusedException 403 banned
     */
    public function open(User $user, ServerRequestInterface $request, SignInMethod $method): SignedIn
    {
        if ($user->isBanned()) {
            throw SignInRefusedException::banned();
        }
        if (!$user->wasRecentlyCreated) {
            $this->customers->seen($user);
        }

        return $this->sessions->open($user, $request, $method);
    }
}
