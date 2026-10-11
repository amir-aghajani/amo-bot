<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Support\Input;
use App\Support\Password;

/**
 * The owner's panel login as a form sends it (the installer, the recovery, the owner's own change), read by one rule:
 * a USERNAME_PATTERN username and a password by App\Support\Password, typed twice alike. AdminAccount::save() keeps it.
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
     * Read `username`, `password` and `password_confirmation`, returning the errors for the caller to add its own to
     * and throw at once. With `$keepPassword`, a blank password and confirmation keep the stored one.
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
