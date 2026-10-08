<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Security\Totp;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * RFC 6238 time-based codes, as the authenticator apps make them: the RFC's own vectors, a code taken in its step and
 * the one either side of it but never once taken, new secrets of 160 bits in base32, and the otpauth:// address an app
 * takes one by.
 */
final class TotpTest extends TestCase
{
    /** RFC 6238's SHA-1 secret, "12345678901234567890", in base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testTheCodesAreTheRfcsOwn(): void
    {
        // Appendix B, SHA-1: the last six digits of each eight-digit code.
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'] as $time => $code) {
            self::assertSame($code, Totp::code(self::RFC_SECRET, $time), "T={$time}");
        }
        self::assertSame(Totp::code(self::RFC_SECRET, 59), Totp::code('gezd gnbv-gy3t qojq gezd gnbv gy3t qojq', 59), 'a secret as typed: spaces, dashes and lower case');
    }

    public function testTheShopsClockIsTheDefault(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1111111109));

        self::assertSame('081804', Totp::code(self::RFC_SECRET));
        self::assertSame(intdiv(1111111109, 30), Totp::verify(self::RFC_SECRET, '081804'));
    }

    public function testACodeOfTheStepNowOrOneEitherSideOfItIsTaken(): void
    {
        $now = 1_800_000_000;
        $step = intdiv($now, 30);

        self::assertSame($step, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $now), null, $now));
        self::assertSame($step - 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $now - 30), null, $now), 'a phone a little behind');
        self::assertSame($step + 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $now + 30), null, $now), 'or ahead');
        self::assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $now - 60), null, $now), 'two steps behind is too old');
        self::assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $now + 60), null, $now));
        foreach (['', '12345', '1234567', '۱۲۳۴۵۶', 'abcdef'] as $none) {
            self::assertNull(Totp::verify(self::RFC_SECRET, $none, null, $now), "«{$none}» is no code");
        }
    }

    public function testACodeOpensOnceAndNoOlderOneAfterIt(): void
    {
        $now = 1_800_000_000;
        $step = intdiv($now, 30);
        $code = Totp::code(self::RFC_SECRET, $now);

        self::assertNull(Totp::verify(self::RFC_SECRET, $code, $step, $now), 'its step taken already');
        self::assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $now - 30), $step, $now), 'an older one after it');
        self::assertSame($step + 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $now + 30), $step, $now), 'a later one still opens');
    }

    public function testANewSecretIs160BitsOfBase32(): void
    {
        $secret = Totp::secret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertNotSame($secret, Totp::secret());
        self::assertSame(intdiv(1_800_000_000, 30), Totp::verify($secret, Totp::code($secret, 1_800_000_000), null, 1_800_000_000), 'its codes read back');
    }

    public function testTheAddressAnAppTakesTheSecretBy(): void
    {
        self::assertSame(
            'otpauth://totp/Amo%20Shop:sara%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Amo%20Shop&algorithm=SHA1&digits=6&period=30',
            Totp::uri('JBSWY3DPEHPK3PXP', 'Amo Shop', 'sara@example.com'),
        );
        self::assertSame(
            'otpauth://totp/%D9%81%D8%B1%D9%88%D8%B4%DA%AF%D8%A7%D9%87%20%D9%85%D9%86:a%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=%D9%81%D8%B1%D9%88%D8%B4%DA%AF%D8%A7%D9%87%20%D9%85%D9%86&algorithm=SHA1&digits=6&period=30',
            Totp::uri('JBSWY3DPEHPK3PXP', 'فروشگاه:من', 'a@example.com'),
            'a Persian name, its colon read as a space — the label\'s own separator stays one',
        );
        self::assertSame('otpauth://totp/a%40example.com?secret=JBSWY3DPEHPK3PXP&algorithm=SHA1&digits=6&period=30', Totp::uri('JBSWY3DPEHPK3PXP', '', 'a@example.com'), 'no issuer, no prefix');
    }

    public function testASecretThatIsNoBase32IsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Totp::code('not base32: 0189');
    }
}
