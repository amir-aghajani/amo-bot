<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\RequestOrigin;
use App\Modules\Auth\Credentials;
use App\Modules\Auth\Exceptions\ShopRefusedException;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Principal;
use App\Support\Input;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The way back into the owner's panel without a shell — a password forgotten, or no login set at all —, from the
 * sign-in page: whoever can read the host's files (its File Manager) proves it with the one-time key the panel writes
 * there (RecoveryKey), and sets a new login by the installer's one rule (Credentials). A wrong key is a failed sign-in,
 * counted per address (SignInThrottle). Done, the key is gone, this browser is signed in under the new login, and every
 * other session ends with the old one.
 */
final class LoginRecovery
{
    public const WRONG_KEY = 'کلید بازیابی درست نیست یا وقتش گذشته است؛ متن فایل ' . RecoveryKey::FILE . ' را دوباره کپی کنید، یا کلید تازه بسازید.';

    /** Its failures, counted apart from the password's: a wrong key is no guess at the owner's password. */
    private const WAY = 'recovery';

    public function __construct(
        private readonly RecoveryKey $key,
        private readonly OwnerAuth $auth,
        private readonly SignInThrottle $throttle,
        private readonly RequestOrigin $origin,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The key written for the owner to read off the host — a new one when there is none that still opens; the same one
     * while it does, so nobody can turn it over while its owner copies it.
     *
     * @return array{file: string, expires_at: string}
     * @throws SignInRefusedException 503 when storage/ cannot be written
     */
    public function start(ServerRequestInterface $request): array
    {
        if ($this->key->expiresAt() === null) {
            try {
                $this->key->current();
            } catch (\RuntimeException $e) {
                $this->logger->error('The recovery key could not be written: {message}', ['message' => $e->getMessage()]);

                throw SignInRefusedException::keyUnwritable();
            }
            $this->logger->warning('A key to set the panel\'s login again was written to {file}, asked from {address}.', ['file' => RecoveryKey::FILE, 'address' => $this->origin->clientIp($request)]);
        }

        return [
            'file' => RecoveryKey::FILE,
            'expires_at' => Carbon::createFromTimestamp((int) $this->key->expiresAt())->toIso8601ZuluString(),
        ];
    }

    /**
     * A new login, set with the key: every refusal of the form at once — the key's among them —, then the shop the
     * request names (the tab's: where this browser is signed in), before anything is written.
     *
     * @param array<string, mixed> $input {key, username, password, password_confirmation}
     * @throws ValidationException 422: the form's refusals, a wrong key, a shop named that is no bot's id (`shop`), or a
     *                             config.php the server may not write (`file`)
     * @throws ShopRefusedException 404: the shop named is not there
     * @throws TooManyAttemptsException 429 while this address must wait
     */
    public function recover(ServerRequestInterface $request, array $input): Principal
    {
        $this->throttle->check(self::WAY, $request);

        [$credentials, $errors] = Credentials::fromInput($input);
        $key = Input::text($input, 'key');
        if ($key === '') {
            $errors['key'][] = 'کلید بازیابی را وارد کنید.';
        } elseif (!$this->key->matches($key)) {
            $this->throttle->failed(self::WAY, $request);
            $errors['key'][] = self::WRONG_KEY;
        }
        ValidationException::ifAny($errors);

        $shop = $this->auth->shop($request);
        $principal = $this->auth->changeCredentials($credentials, $shop);
        $this->key->forget();
        $this->throttle->passed(self::WAY, $request);
        $this->logger->warning('The panel\'s login was set again with the recovery key, from {address}: signed in as "{username}", every other session signed out.', ['address' => $this->origin->clientIp($request), 'username' => $credentials->username]);

        return $principal;
    }
}
