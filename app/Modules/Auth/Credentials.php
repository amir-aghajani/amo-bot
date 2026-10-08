<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Support\Input;
use App\Support\Password;

/**
 * The panel's login as a form gives it — the installer's step, the login's recovery and the owner's own change from
 * their panel —, read by the one rule for all three: a username of USERNAME_PATTERN, and a password by the one password
 * rule (App\Support\Password — a customer's on the shop's website too), typed twice alike. AdminAccount::save() writes
 * it.
 */
final class Credentials
{
    /** What a login name may be: 2–64 letters, digits, dots, underscores, @ or dashes. */
    public const USERNAME_PATTERN = '/^[A-Za-z0-9._@-]{2,64}$/';

    private function __construct(
        public readonly string $username,
        /** Null: the password kept stays (the owner renaming only their login). */
        public readonly ?string $password,
    ) {}

    /**
     * The form's `username`, `password` and `password_confirmation`, with the messages of the fields it refused — the
     * caller adds its own before it throws them all at once. `$keepPassword`: a password left blank, its confirmation
     * too, keeps the one kept instead of being refused.
     *
     * @param array<string, mixed> $input
     * @return array{self, array<string, list<string>>}
     */
    public static function fromInput(array $input, bool $keepPassword = false): array
    {
        $username = Input::text($input, 'username');
        $password = Input::password($input, 'password');
        $confirmation = Input::password($input, 'password_confirmation');
        $kept = $keepPassword && $password === '' && $confirmation === '';

        $errors = [];
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            $errors['username'][] = 'نام کاربری 2 تا 64 کاراکتر است: حروف انگلیسی، عدد، نقطه، _، @ یا -.';
        }
        $problem = $kept ? null : Password::problem($password);
        if ($problem !== null) {
            $errors['password'][] = $problem;
        } elseif (!$kept && $password !== $confirmation) {
            $errors['password_confirmation'][] = 'رمز عبور و تکرارش یکی نیستند.';
        }

        return [new self($username, $kept ? null : $password), $errors];
    }
}
