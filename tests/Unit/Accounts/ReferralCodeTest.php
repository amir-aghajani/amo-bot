<?php

declare(strict_types=1);

namespace Tests\Unit\Accounts;

use App\Modules\Accounts\Services\ProviderSignIn;
use PHPUnit\Framework\TestCase;

/**
 * The invite code a newcomer's sign-in carries (Telegram, Google, a code by SMS, the mini app's launch): read cut short,
 * as an email sign-up's is — a code is eight characters, and nothing longer goes on to be looked up.
 */
final class ReferralCodeTest extends TestCase
{
    public function testTheCodeIsReadCutShortAndNoneIsNull(): void
    {
        self::assertSame('k7m2xq9p', ProviderSignIn::referralCode(['referral_code' => ' k7m2xq9p ']));
        self::assertSame(str_repeat('a', ProviderSignIn::REFERRAL_MAX), ProviderSignIn::referralCode(['referral_code' => str_repeat('a', 100_000)]));
        self::assertNull(ProviderSignIn::referralCode(['referral_code' => '']));
        self::assertNull(ProviderSignIn::referralCode([]));
        self::assertNull(ProviderSignIn::referralCode(['referral_code' => ['k7m2xq9p']]));
    }
}
