<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Password;
use PHPUnit\Framework\TestCase;

/**
 * The one rule for a password — the panel's owner's and a customer's alike: eight characters at least, and no more than
 * bcrypt reads (72 bytes — a Persian letter is two) nor a NUL byte, where it stops reading, else it would open with its
 * start alone; kept as PHP's own hash.
 */
final class PasswordTest extends TestCase
{
    public function testAPasswordIsEightCharactersAtLeastAndWhatBcryptReadsAtMost(): void
    {
        self::assertNull(Password::problem('12345678'));
        self::assertNull(Password::problem(str_repeat('a', Password::MAX_BYTES)));
        self::assertNull(Password::problem('رمز عبور'), 'eight characters, Persian ones too');
        self::assertSame('رمز عبور دست‌کم 8 کاراکتر است.', Password::problem('1234567'));
        self::assertSame('رمز عبور حداکثر 72 بایت است: 72 حرف انگلیسی، یا نصف آن حرف فارسی.', Password::problem(str_repeat('ر', 37)));
    }

    public function testAPasswordWithANulByteIsNone(): void
    {
        // bcrypt stops reading at a NUL: "abcdefgh\0anything" would open with "abcdefgh" alone.
        self::assertSame(Password::NUL, Password::problem("abcdefgh\0xyz"));
        self::assertSame(Password::NUL, Password::problem("\0"), 'said before its length');
    }

    public function testItIsKeptAsPhpsOwnHash(): void
    {
        $hash = Password::hash(' a secret with spaces ');

        self::assertTrue(password_verify(' a secret with spaces ', $hash), 'as typed, spaces and all');
        self::assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT));
    }
}
