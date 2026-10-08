<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Modules\Auth\Credentials;
use App\Support\Password;
use PHPUnit\Framework\TestCase;

/**
 * The one rule for the panel's login, wherever it is set (the installer, the recovery, the owner's own change): a
 * password long enough, and no longer than what bcrypt reads of it — past that a hash would open the panel with only its
 * start —, counted in bytes, so a Persian letter is two.
 */
final class CredentialsTest extends TestCase
{
    public function testAPasswordBcryptReadsWholeIsTaken(): void
    {
        $password = str_repeat('a', Password::MAX_BYTES);
        [$credentials, $errors] = Credentials::fromInput(['username' => 'owner', 'password' => $password, 'password_confirmation' => $password]);

        self::assertSame([], $errors);
        self::assertSame($password, $credentials->password);
    }

    public function testALongerOneIsRefusedInBytesSoPersianLettersCountTwice(): void
    {
        $persian = str_repeat('ر', intdiv(Password::MAX_BYTES, 2) + 1);

        foreach ([str_repeat('a', Password::MAX_BYTES + 1), $persian] as $password) {
            [, $errors] = Credentials::fromInput(['username' => 'owner', 'password' => $password, 'password_confirmation' => $password]);

            self::assertSame(['password'], array_keys($errors), $password);
            self::assertStringContainsString((string) Password::MAX_BYTES, $errors['password'][0]);
        }
    }

    public function testTooShortStaysTheFirstWordAndAKeptPasswordIsNotMeasured(): void
    {
        [, $errors] = Credentials::fromInput(['username' => 'owner', 'password' => 'short', 'password_confirmation' => 'short']);
        self::assertStringContainsString((string) Password::MIN_LENGTH, $errors['password'][0]);

        [$credentials, $errors] = Credentials::fromInput(['username' => 'owner', 'password' => '', 'password_confirmation' => ''], keepPassword: true);
        self::assertSame([], $errors);
        self::assertNull($credentials->password, 'left blank, the kept one stays');
    }
}
