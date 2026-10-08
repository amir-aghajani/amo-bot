<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Config\ConfigFile;
use App\Core\Config\Repository as Config;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Credentials;
use App\Support\Password;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The panel's one login, as config.php keeps it: a username (ADMIN_USERNAME) and the password's hash
 * (ADMIN_PASSWORD_HASH). It is not a user: the bot's own admins are customer rows with the admin role, which is a
 * different thing. The web installer, the owner from their panel (OwnerAuth::changeCredentials()) and its recovery
 * with a key off the host's files (LoginRecovery) write it through `save()`. A password kept in plain text —
 * typed into the file by hand — is refused: the panel stays closed, and the log says why, until one is set again. What
 * the owner's session alone should not change — the login itself, the Bot API's address every bot's token goes to —
 * asks the current password typed again (confirm()).
 */
final class AdminAccount
{
    /** The owner's password, wherever it is typed — the login, its change, a setting it guards: one count of its failures (SignInThrottle). */
    public const WAY = 'login';

    /** A current password that is not the one kept. */
    public const WRONG_PASSWORD = 'رمز عبور فعلی درست نیست.';

    private const NO_PASSWORD = 'رمز عبور فعلی را وارد کنید.';

    /** Whether this process said already that the password is kept in plain text (once is enough). */
    private bool $toldPlainText = false;

    public function __construct(
        private readonly Config $config,
        private readonly ConfigFile $file,
        private readonly SignInThrottle $throttle,
        private readonly LoggerInterface $logger,
    ) {}

    /** Whether config.php names a login with a hashed password — until it does nobody can sign in. */
    public function configured(): bool
    {
        return $this->username() !== '' && $this->hashed();
    }

    public function username(): string
    {
        return trim((string) $this->config->get('admin.username', ''));
    }

    /** Both parts, in constant time: the username as written, the password against its hash. */
    public function verify(string $username, string $password): bool
    {
        if (!$this->configured() || $password === '') {
            return false;
        }

        $passwordOk = password_verify($password, $this->secret());

        return hash_equals($this->username(), trim($username)) && $passwordOk;
    }

    /**
     * The owner's current password, typed again to change what it guards: why it proves nothing — none typed, or not the
     * one kept —, else null. Judged as a sign-in is (SignInThrottle::password()): counted with the login's failures, per
     * address and per username, so it is no way around the throttle to guess it.
     *
     * @throws TooManyAttemptsException 429 while this address, or the account, must wait
     */
    public function confirm(ServerRequestInterface $request, string $password): ?string
    {
        if ($password === '') {
            return self::NO_PASSWORD;
        }
        $account = $this->username();

        return $this->throttle->password(self::WAY, $request, $account, fn(): bool => $this->verify($account, $password)) ? null : self::WRONG_PASSWORD;
    }

    /** Changes with the credentials, so a session opened under old ones stops being valid. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->username() . "\0" . $this->secret());
    }

    /**
     * Write the login to config.php — the password as a bcrypt hash, the hash kept when the password stays — and use it
     * from now on: a session opened under the previous one ends with it.
     *
     * @throws ValidationException when the file cannot be written: what to fix, under `file`; nothing changed then
     */
    public function save(Credentials $credentials): void
    {
        $hash = $credentials->password === null ? $this->secret() : Password::hash($credentials->password);

        try {
            $this->file->setMany(['ADMIN_USERNAME' => $credentials->username, 'ADMIN_PASSWORD_HASH' => $hash]);
        } catch (\RuntimeException $e) {
            $this->logger->error('The panel\'s login could not be written: {message}', ['message' => $e->getMessage()]);

            throw ValidationException::on('file', ConfigFile::UNWRITABLE);
        }

        $this->config->set('admin.username', $credentials->username);
        $this->config->set('admin.password', $hash);
    }

    /** Whether the kept password is a hash password_verify() knows; one in plain text is refused, and said in the log. */
    private function hashed(): bool
    {
        $secret = $this->secret();
        if ($secret === '') {
            return false;
        }
        if (password_get_info($secret)['algo'] !== null) {
            return true;
        }

        if (!$this->toldPlainText) {
            $this->toldPlainText = true;
            $this->logger->warning('The panel stays closed: ADMIN_PASSWORD_HASH in {file} is no password hash. Set the login again from the sign-in page\'s recovery («رمز را فراموش کرده‌اید؟»).', ['file' => $this->file->path()]);
        }

        return false;
    }

    private function secret(): string
    {
        return (string) $this->config->get('admin.password', '');
    }
}
