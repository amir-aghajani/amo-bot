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
 * The owner's panel login, kept in config.php (ADMIN_USERNAME, ADMIN_PASSWORD_HASH) — not a user row: the bot's admins
 * are customers with the admin role. Written by save() (installer, the owner's change, recovery). A password kept in
 * plain text is refused and logged: the panel stays closed until one is set. What a session alone should not change
 * (the login itself, the Bot API's address) asks the current password again (confirm()).
 */
final class AdminAccount
{
    /** The throttle's way for the owner's password, wherever it is typed: one count of its failures. */
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

    /** Whether config.php has a login with a hashed password; until then nobody can sign in. */
    public function configured(): bool
    {
        return $this->username() !== '' && $this->hashed();
    }

    public function username(): string
    {
        return trim((string) $this->config->get('admin.username', ''));
    }

    /** Check both parts; the password is verified even when the username is wrong, so timing tells nothing. */
    public function verify(string $username, string $password): bool
    {
        if (!$this->configured() || $password === '') {
            return false;
        }

        $passwordOk = password_verify($password, $this->secret());

        return hash_equals($this->username(), trim($username)) && $passwordOk;
    }

    /**
     * Check the owner's current password typed again: why it fails, else null. Counted with the login's failures, per
     * address and per username, so it is no way around the throttle.
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

    /** Changes with the credentials, so a session opened under old ones ends. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->username() . "\0" . $this->secret());
    }

    /**
     * Write the login to config.php (the password hashed, or the hash kept when it stays) and use it from now on.
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

    /** Whether the kept password is a hash password_verify() knows; plain text is refused and logged once. */
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
